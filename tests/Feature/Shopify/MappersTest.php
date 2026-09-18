<?php

use App\Enums\ConversationStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Shopify\Sync\Mappers\CustomerMapper;
use App\Shopify\Sync\Mappers\InventoryMapper;
use App\Shopify\Sync\Mappers\MapResult;
use App\Shopify\Sync\Mappers\OrderMapper;
use App\Shopify\Sync\Mappers\ProductMapper;
use App\Shopify\Sync\Mappers\ShippingZoneMapper;
use App\Shopify\Sync\Mappers\StaleGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

function fixture(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/Fixtures/shopify/{$name}.json")), true);
}

beforeEach(fn () => Event::fake());

it('upserts products idempotently and ignores stale updates', function () {
    $p = fixture('webhook_product');
    expect(app(ProductMapper::class)->upsert($p))->toBe(MapResult::Created);
    expect(app(ProductMapper::class)->upsert($p))->toBe(MapResult::Skipped);
    $newer = array_replace($p, ['title' => 'فستان محدث', 'updated_at' => '2030-01-01T00:00:00+02:00']);
    expect(app(ProductMapper::class)->upsert($newer))->toBe(MapResult::Updated);
    expect(Product::where('shopify_id', (string) $p['id'])->value('title'))->toBe('فستان محدث')
        ->and(ProductVariant::count())->toBe(count($p['variants']));
});

it('applies inventory level to the matching variant', function () {
    app(ProductMapper::class)->upsert(fixture('webhook_product'));
    $level = fixture('webhook_inventory_level');
    app(InventoryMapper::class)->apply($level);
    expect(ProductVariant::where('inventory_item_id', (string) $level['inventory_item_id'])->value('inventory_quantity'))->toBe($level['available']);
});

it('creates a store order with its customer, items and fulfillment', function () {
    $o = fixture('webhook_order_store');
    app(OrderMapper::class)->upsert($o);
    $order = Order::where('shopify_order_id', (string) $o['id'])->firstOrFail();
    expect($order->source)->toBe(OrderSource::Store)->and($order->items)->toHaveCount(count($o['line_items']))
        ->and($order->customer->shopify_customer_id)->toBe((string) $o['customer']['id']);
    app(OrderMapper::class)->applyFulfillment(fixture('webhook_fulfillment'));
    expect($order->fresh()->fulfillments->first()->tracking_number)->not->toBeNull();
});

it('stores the shopify creation time as placed_at and never blanks it', function () {
    $o = array_replace(fixture('webhook_order_store'), ['created_at' => '2026-08-01T10:00:00+03:00', 'processed_at' => '2026-08-01T10:05:00+03:00']);
    app(OrderMapper::class)->upsert($o);
    $order = Order::where('shopify_order_id', (string) $o['id'])->firstOrFail();

    expect($order->placed_at->equalTo(Carbon::parse('2026-08-01T10:00:00+03:00')))->toBeTrue();

    app(OrderMapper::class)->upsert(array_replace($o, ['created_at' => null, 'processed_at' => null, 'updated_at' => '2030-01-01T00:00:00+02:00']));

    expect($order->fresh()->placed_at->equalTo(Carbon::parse('2026-08-01T10:00:00+03:00')))->toBeTrue();
});

it('falls back to processed_at for placed_at', function () {
    $o = fixture('webhook_order_store');
    unset($o['created_at']);
    $o['processed_at'] = '2026-08-02T09:00:00+03:00';
    app(OrderMapper::class)->upsert($o);

    expect(Order::where('shopify_order_id', (string) $o['id'])->firstOrFail()->placed_at->equalTo(Carbon::parse('2026-08-02T09:00:00+03:00')))->toBeTrue();
});

it('links a webhook to the local chat order by crm_order_id without changing attribution', function () {
    $local = Order::factory()->create(['status' => OrderStatus::Submitting, 'source' => OrderSource::Chat, 'shopify_order_id' => null]);
    $o = fixture('webhook_order_chat');
    $o['note_attributes'] = [['name' => 'crm_order_id', 'value' => (string) $local->id]];
    app(OrderMapper::class)->upsert($o);
    $fresh = $local->fresh();
    expect($fresh->shopify_order_id)->toBe((string) $o['id'])->and($fresh->created_by_id)->toBe($local->created_by_id)
        ->and($fresh->source)->toBe(OrderSource::Chat)->and(Order::count())->toBe(1);
});

it('ignores a crm_order_id note attribute that does not identify a matching local chat order', function () {
    // A crm_order_id note attribute rides on a public Shopify checkout/cart and
    // can be set by any shopper: it must never link a store payload to an
    // unrelated chat order, and never to a chat order already linked to a
    // *different* Shopify order.
    $unrelatedChat = Order::factory()->create(['source' => OrderSource::Chat, 'shopify_order_id' => '999999']);
    $o = fixture('webhook_order_store');
    $o['note_attributes'] = [['name' => 'crm_order_id', 'value' => (string) $unrelatedChat->id]];

    expect(app(OrderMapper::class)->upsert($o))->toBe(MapResult::Created);

    $created = Order::where('shopify_order_id', (string) $o['id'])->firstOrFail();
    expect($created->source)->toBe(OrderSource::Store)->and($created->id)->not->toBe($unrelatedChat->id)
        ->and($unrelatedChat->fresh()->shopify_order_id)->toBe('999999')
        ->and(Order::count())->toBe(2);
});

it('keeps a store order money field at its stored value when the payload omits every key for it', function () {
    $o = fixture('webhook_order_store');
    app(OrderMapper::class)->upsert($o);
    $order = Order::where('shopify_order_id', (string) $o['id'])->firstOrFail();
    expect($order->shipping_fee)->toBe('60.00');

    $partial = $o;
    unset($partial['total_shipping_price_set']);
    $partial['updated_at'] = '2026-09-12T00:00:00Z';

    app(OrderMapper::class)->upsert($partial);
    expect($order->fresh()->shipping_fee)->toBe('60.00');
});

it('normalizes GraphQL financial and fulfillment status vocabulary on an order node', function () {
    $node = [
        'id' => 'gid://shopify/Order/700',
        'name' => '#700',
        'updatedAt' => '2026-09-10T10:00:00Z',
        'currencyCode' => 'EGP',
        'displayFinancialStatus' => 'PARTIALLY_REFUNDED',
        'displayFulfillmentStatus' => 'PARTIALLY_FULFILLED',
        'shippingAddress' => null,
        'billingAddress' => null,
    ];

    app(OrderMapper::class)->upsert($node);
    $order = Order::where('shopify_order_id', '700')->firstOrFail();
    expect($order->financial_status)->toBe('partially_refunded')
        ->and($order->fulfillment_status)->toBe('partial');

    $unfulfilled = array_replace($node, ['displayFulfillmentStatus' => 'UNFULFILLED', 'updatedAt' => '2026-09-11T00:00:00Z']);
    app(OrderMapper::class)->upsert($unfulfilled);
    expect($order->fresh()->fulfillment_status)->toBeNull();

    $fulfilled = array_replace($node, ['displayFulfillmentStatus' => 'FULFILLED', 'updatedAt' => '2026-09-12T00:00:00Z']);
    app(OrderMapper::class)->upsert($fulfilled);
    expect($order->fresh()->fulfillment_status)->toBe('fulfilled');
});

it('posts a system line when a store order arrives for a customer with an open conversation', function () {
    $o = fixture('webhook_order_store');
    $customer = Customer::factory()->create(['shopify_customer_id' => (string) $o['customer']['id']]);
    $conv = Conversation::factory()->for($customer)->for(ChannelAccount::factory(), 'channelAccount')->create();
    app(OrderMapper::class)->upsert($o);
    expect($conv->messages()->where('sender_type', 'system')->value('body'))->toContain('طلب جديد من الموقع');
});

// --- Additional coverage -------------------------------------------------------

it('treats equal or older timestamps as stale and null stored values as fresh', function () {
    $stored = Carbon::parse('2026-09-10T11:00:00Z');
    expect(StaleGuard::isStale(null, '2020-01-01T00:00:00Z'))->toBeFalse()
        ->and(StaleGuard::isStale($stored, '2026-09-10T14:00:00+03:00'))->toBeTrue()
        ->and(StaleGuard::isStale($stored, '2026-09-10T13:59:59+03:00'))->toBeTrue()
        ->and(StaleGuard::isStale($stored, '2026-09-10T14:00:01+03:00'))->toBeFalse()
        ->and(StaleGuard::isStale($stored, null))->toBeFalse();
});

it('maps product fields and variants from the REST payload', function () {
    $p = fixture('webhook_product');
    app(ProductMapper::class)->upsert($p);
    $product = Product::with('variants')->where('shopify_id', '8123456789012')->firstOrFail();
    $m = $product->variants->firstWhere('shopify_id', '45123456789001');
    $l = $product->variants->firstWhere('shopify_id', '45123456789002');

    expect($product->title)->toBe('فستان سهرة شيفون')
        ->and($product->tags)->toBe(['سهرة', 'شيفون', 'جديد'])
        ->and($product->image_url)->toContain('dress-black.jpg')
        ->and($product->shopify_updated_at->utc()->toIso8601String())->toBe('2026-09-10T11:00:00+00:00')
        ->and($m->price)->toBe('450.00')->and($m->compare_at_price)->toBe('550.00')
        ->and($m->inventory_item_id)->toBe('47123456789001')
        ->and($l->price)->toBe('480.00')->and($l->inventory_policy)->toBe('continue');

    // A newer payload without one variant removes it.
    $p['updated_at'] = '2026-09-11T00:00:00Z';
    $p['variants'] = [$p['variants'][0]];
    app(ProductMapper::class)->upsert($p);
    expect(ProductVariant::count())->toBe(1);

    app(ProductMapper::class)->delete('8123456789012');
    expect(Product::count())->toBe(0)->and(Product::withTrashed()->count())->toBe(1);

    // Re-created on Shopify later: restores the soft-deleted row.
    $p['updated_at'] = '2026-09-12T00:00:00Z';
    expect(app(ProductMapper::class)->upsert($p))->toBe(MapResult::Updated)->and(Product::count())->toBe(1);
});

it('accepts a GraphQL product node', function () {
    $node = [
        'id' => 'gid://shopify/Product/900',
        'title' => 'عباية كتان',
        'handle' => 'linen-abaya',
        'status' => 'ACTIVE',
        'vendor' => 'Arena',
        'productType' => 'عبايات',
        'tags' => ['كتان', 'صيفي'],
        'updatedAt' => '2026-09-10T10:00:00Z',
        'featuredImage' => ['url' => 'https://cdn.shopify.com/abaya.jpg'],
        'variants' => ['nodes' => [[
            'id' => 'gid://shopify/ProductVariant/901',
            'title' => 'Default Title',
            'sku' => 'ABY-LIN',
            'price' => '799.5',
            'compareAtPrice' => null,
            'barcode' => null,
            'inventoryQuantity' => 8,
            'inventoryPolicy' => 'DENY',
            'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/902', 'requiresShipping' => true],
            'updatedAt' => '2026-09-10T10:00:00Z',
        ]]],
    ];

    expect(app(ProductMapper::class)->upsert($node))->toBe(MapResult::Created);
    $v = ProductVariant::where('shopify_id', '901')->firstOrFail();
    expect(Product::where('shopify_id', '900')->value('status'))->toBe('active')
        ->and($v->price)->toBe('799.50')->and($v->inventory_item_id)->toBe('902')
        ->and($v->inventory_policy)->toBe('deny')->and($v->inventory_quantity)->toBe(8);
});

it('stores store order statuses, totals and item details', function () {
    app(ProductMapper::class)->upsert(fixture('webhook_product'));
    app(OrderMapper::class)->upsert(fixture('webhook_order_store'));
    $order = Order::with('items')->where('shopify_order_id', '5987654321001')->firstOrFail();
    $dress = $order->items->firstWhere('shopify_line_item_id', '15123456789001');
    $hijab = $order->items->firstWhere('shopify_line_item_id', '15123456789002');

    expect($order->status)->toBe(OrderStatus::Confirmed)
        ->and($order->financial_status)->toBe('pending')
        ->and($order->fulfillment_status)->toBeNull()
        ->and($order->shopify_order_name)->toBe('#1234')
        ->and($order->total)->toBe('685.00')
        ->and($order->shipping_fee)->toBe('60.00')
        ->and($order->discount)->toBe('25.00')
        ->and($order->shipping_city)->toBe('مدينة نصر')
        ->and($dress->title)->toBe('فستان سهرة شيفون')
        ->and($dress->discount)->toBe('25.00')
        ->and($dress->variant_id)->toBe(ProductVariant::where('shopify_id', '45123456789001')->value('id'))
        ->and($hijab->qty)->toBe(2)->and($hijab->price)->toBe('100.00')->and($hijab->variant_id)->toBeNull();
});

it('skips a stale order update without writing anything', function () {
    $o = fixture('webhook_order_store');
    expect(app(OrderMapper::class)->upsert($o))->toBe(MapResult::Created);
    $older = array_replace($o, ['current_total_price' => '1.00', 'updated_at' => '2026-09-10T14:00:00+03:00']);
    expect(app(OrderMapper::class)->upsert($older))->toBe(MapResult::Skipped)
        ->and(Order::value('total'))->toBe('685.00');

    $newer = array_replace($o, ['financial_status' => 'paid', 'updated_at' => '2026-09-10T15:00:00+03:00']);
    $newer['line_items'] = [$o['line_items'][0]];
    expect(app(OrderMapper::class)->upsert($newer))->toBe(MapResult::Updated);
    $order = Order::firstOrFail();
    expect($order->financial_status)->toBe('paid')->and($order->items()->count())->toBe(1)->and(Order::count())->toBe(1);
});

it('matches a chat order by its draft order id', function () {
    $local = Order::factory()->create(['source' => OrderSource::Chat, 'shopify_draft_order_id' => '1122334455', 'status' => OrderStatus::AwaitingPayment]);
    $o = fixture('webhook_order_chat');
    $o['note_attributes'] = [];
    $o['draft_order_id'] = 1122334455;
    app(OrderMapper::class)->upsert($o);
    $fresh = $local->fresh();
    // OrderMapper::upsert alone never confirms a chat order (Task 4 ruling):
    // that transition is ShopifyWebhookProcessor's job, via OrderService, once
    // it re-reads the order's pre-webhook status. The mapper only syncs
    // Shopify identity/status fields here.
    expect(Order::count())->toBe(1)->and($fresh->shopify_order_id)->toBe('5987654321002')
        ->and($fresh->status)->toBe(OrderStatus::AwaitingPayment)->and($fresh->customer_id)->toBe($local->customer_id)
        ->and($fresh->shopify_order_name)->toBe('#1235')->and($fresh->financial_status)->toBe('pending');
});

it('does not post the system line on updates or for resolved conversations', function () {
    $o = fixture('webhook_order_store');
    $customer = Customer::factory()->create(['shopify_customer_id' => (string) $o['customer']['id']]);
    $resolved = Conversation::factory()->for($customer)->create(['status' => ConversationStatus::Resolved, 'last_message_at' => now()]);
    app(OrderMapper::class)->upsert($o);
    expect($resolved->messages()->count())->toBe(0);

    $open = Conversation::factory()->for($customer)->create(['status' => ConversationStatus::Pending]);
    app(OrderMapper::class)->upsert(array_replace($o, ['updated_at' => '2026-09-12T00:00:00Z']));
    expect($open->messages()->count())->toBe(0);
});

it('creates a customer from the shipping address when the order has no customer', function () {
    $o = fixture('webhook_order_store');
    unset($o['customer']);
    $o['shipping_address']['phone'] = 'مش موجود';
    app(OrderMapper::class)->upsert($o);
    $order = Order::with('customer')->firstOrFail();
    expect($order->customer->name)->toBe('نور علي')->and($order->customer->shopify_customer_id)->toBeNull();
});

it('reuses an existing customer by normalized shipping phone', function () {
    // Event::fake() mutes the model saving hook, so set the normalized phone explicitly.
    $existing = Customer::factory()->create(['phone' => '+20 100 123 4567', 'normalized_phone' => '+201001234567']);
    $o = fixture('webhook_order_store');
    unset($o['customer']);
    app(OrderMapper::class)->upsert($o);
    expect(Order::value('customer_id'))->toBe($existing->id)->and(Customer::count())->toBe(1);
});

it('applies refunds idempotently and marks cancellations', function () {
    app(OrderMapper::class)->upsert(fixture('webhook_order_store'));
    expect(app(OrderMapper::class)->applyRefund(fixture('webhook_refund')))->toBe(MapResult::Created)
        ->and(app(OrderMapper::class)->applyRefund(fixture('webhook_refund')))->toBe(MapResult::Skipped);
    $order = Order::firstOrFail();
    expect($order->refunds)->toHaveCount(1)->and($order->refunds->first()->amount)->toBe('200.00')
        ->and($order->customer->fresh()->has_return)->toBeTrue();

    $cancelled = array_replace(fixture('webhook_order_store'), [
        'cancelled_at' => '2026-09-15T10:00:00+03:00', 'cancel_reason' => 'customer', 'updated_at' => '2026-09-15T10:00:01+03:00',
    ]);
    expect(app(OrderMapper::class)->markCancelled($cancelled))->toBe(MapResult::Updated);
    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Cancelled)->and($order->cancel_reason)->toBe('customer')
        ->and($order->customer->fresh()->has_open_order)->toBeFalse();
});

it('updates an existing fulfillment and ignores stale fulfillment payloads', function () {
    app(OrderMapper::class)->upsert(fixture('webhook_order_store'));
    $f = fixture('webhook_fulfillment');
    expect(app(OrderMapper::class)->applyFulfillment($f))->toBe(MapResult::Created)
        ->and(app(OrderMapper::class)->applyFulfillment($f))->toBe(MapResult::Skipped);
    $f['shipment_status'] = 'delivered';
    $f['updated_at'] = '2026-09-12T12:00:00+03:00';
    expect(app(OrderMapper::class)->applyFulfillment($f))->toBe(MapResult::Updated);
    expect(Order::firstOrFail()->fulfillments()->count())->toBe(1)
        ->and(Order::firstOrFail()->fulfillments()->value('shipment_status'))->toBe('delivered');
});

it('upserts customers with addresses and skips stale ones', function () {
    $c = fixture('webhook_customer');
    expect(app(CustomerMapper::class)->upsert($c))->toBe(MapResult::Created)
        ->and(app(CustomerMapper::class)->upsert($c))->toBe(MapResult::Skipped);
    $customer = Customer::with('addresses')->where('shopify_customer_id', '7123456789001')->firstOrFail();
    expect($customer->name)->toBe('نور علي')
        ->and($customer->normalized_phone)->toBe('+201001234567')
        ->and($customer->tags)->toBe(['VIP', 'واتساب'])
        ->and($customer->accepts_marketing)->toBeTrue()
        ->and($customer->shopify_orders_count)->toBe(3)
        ->and($customer->shopify_total_spent)->toBe('1850.50')
        ->and($customer->addresses)->toHaveCount(2)
        ->and($customer->addresses->firstWhere('is_default', true)->province)->toBe('Cairo');

    $c['updated_at'] = '2026-09-12T00:00:00Z';
    $c['addresses'] = [$c['addresses'][1]];
    expect(app(CustomerMapper::class)->upsert($c))->toBe(MapResult::Updated)
        ->and($customer->addresses()->count())->toBe(1);
});

it('replaces shipping zones, regions and active rates transactionally', function () {
    ShippingZone::factory()->create(['name' => 'قديمة']);
    expect(app(ShippingZoneMapper::class)->replaceAll(fixture('shipping_zones')))->toBe(2);
    $cairo = ShippingZone::with(['regions', 'rates'])->where('shopify_zone_id', '412345670001')->firstOrFail();
    $free = $cairo->rates->firstWhere('shopify_rate_id', '712345670002');

    expect(ShippingZone::count())->toBe(2)
        ->and($cairo->regions->pluck('province_code')->all())->toBe(['C', 'GZ'])
        ->and($cairo->rates)->toHaveCount(2)
        ->and($free->price)->toBe('0.00')->and($free->min_order_subtotal)->toBe('1500.00')
        ->and(ShippingRate::where('title', 'شحن الدلتا')->value('max_order_subtotal'))->toBe('5000.00')
        ->and(ShippingRate::where('title', 'شحن الدلتا')->value('price'))->toBe('75.00');
});
