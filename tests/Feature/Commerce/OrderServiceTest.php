<?php

use App\Analytics\ActivityLogger;
use App\Commerce\{OrderService, FakeCommerceProvider};
use App\Commerce\Data\OrderStatusUpdate;
use App\Enums\{Platform, OrderStatus, OrderType, UserRole, ShipmentStatus};
use App\Models\{ActivityLog, ChannelAccount, City, Conversation, Customer, Order, Product, ProductVariant, Shipment, User};
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake();
    FakeCommerceProvider::$payloads = [];
    FakeCommerceProvider::$forceError = null;
    $acc = ChannelAccount::factory()->create(['platform'=>Platform::Instagram]);
    $this->conv = Conversation::factory()->for(Customer::factory())->for($acc,'channelAccount')->create(['platform'=>Platform::Instagram]);
    $this->variant = ProductVariant::factory()->for(Product::factory())->create(['price'=>500,'shopify_id'=>'111']);
    $this->city = City::factory()->create(['shipping_fee'=>60]);
    $this->mod = User::factory()->create(['role'=>UserRole::Moderator,'name'=>'Mona Ali']);
    $this->mod->userPlatforms()->create(['platform'=>Platform::Instagram]);
});

function orderData(array $o = []): array {
    return array_merge(['type'=>'cod','items'=>[['variant_id'=>test()->variant->id,'qty'=>2,'price'=>1]],
        'shipping'=>['name'=>'Nour','phone'=>'01001234567','city_id'=>test()->city->id,'address'=>'12 شارع النصر'],'discount'=>0], $o);
}

it('creates a cod order with db prices, attribution tags and a shipment', function () {
    $order = app(OrderService::class)->create($this->conv, $this->mod, orderData());
    $payload = FakeCommerceProvider::$payloads[0];
    expect((float) $order->total)->toBe(1060.0)->and($order->status)->toBe(OrderStatus::Confirmed)
        ->and($payload->tags)->toContain('social-crm', 'platform:instagram', 'mod:mona-ali')
        ->and($payload->note)->toStartWith('Created by Mona Ali from Instagram conversation #'.$this->conv->id)
        ->and($order->shipment->status)->toBe(ShipmentStatus::Created)
        ->and($order->created_by_id)->toBe($this->mod->id);
});

it('creates a payment link awaiting payment', function () {
    $order = app(OrderService::class)->create($this->conv, $this->mod, orderData(['type'=>'payment_link']));
    expect($order->status)->toBe(OrderStatus::AwaitingPayment)->and($order->invoice_url)->toStartWith('https://')
        ->and($order->shipment)->toBeNull();
    app(OrderService::class)->markPaid($order);
    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed)->and($order->fresh()->shipment)->not->toBeNull();
});

it('forbids moderator discounts', function () {
    app(OrderService::class)->create($this->conv, $this->mod, orderData(['discount'=>50]));
})->throws(Illuminate\Auth\Access\AuthorizationException::class);

it('falls back to the email local-part for a mod tag when the name has no latin transliteration', function () {
    $arabicMod = User::factory()->create(['role'=>UserRole::Moderator,'name'=>'منى علي','email'=>'mona@crm.test']);
    $arabicMod->userPlatforms()->create(['platform'=>Platform::Instagram]);

    app(OrderService::class)->create($this->conv, $arabicMod, orderData());

    expect(FakeCommerceProvider::$payloads[0]->tags)->toContain('mod:mona');
});

it('announces a shopify failure instead of a success line', function () {
    FakeCommerceProvider::$forceError = 'Variant out of stock';

    $order = app(OrderService::class)->create($this->conv, $this->mod, orderData());

    expect($order->status)->toBe(OrderStatus::Failed);

    $last = $this->conv->messages()->latest('id')->first();
    expect($last->body)->toBe('تعذر إنشاء الأوردر على Shopify — Variant out of stock');
});

it('persists the shopify order id from a draft-to-order conversion so later webhooks can find it', function () {
    $order = Order::factory()->create([
        'type' => OrderType::PaymentLink,
        'status' => OrderStatus::AwaitingPayment,
        'shopify_draft_order_id' => '800123',
        'shopify_order_id' => null,
    ]);

    $service = app(OrderService::class);

    $service->applyUpdate(new OrderStatusUpdate(
        shopifyOrderId: '9002',
        draftOrderId: '800123',
        financialStatus: 'paid',
        fulfillmentStatus: null,
        cancelled: false,
        orderNumber: '#2002',
    ));

    expect($order->fresh()->shopify_order_id)->toBe('9002')
        ->and($order->fresh()->status)->toBe(OrderStatus::Confirmed)
        ->and($order->fresh()->shipment)->not->toBeNull();

    // A later orders/paid webhook only carries the now-persisted order id, not the draft id.
    $service->applyUpdate(new OrderStatusUpdate(
        shopifyOrderId: '9002',
        draftOrderId: null,
        financialStatus: 'paid',
        fulfillmentStatus: null,
        cancelled: false,
        orderNumber: '#2002',
    ));

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed)
        ->and(Shipment::where('order_id', $order->id)->count())->toBe(1);
});

it('is idempotent when markPaid is called twice on stale instances of the same order', function () {
    $order = Order::factory()->create(['status' => OrderStatus::AwaitingPayment]);
    $order->customer->update(['orders_count' => 0, 'total_spent' => 0]);

    // Two independent, equally-stale reads of the same row (simulates duplicate webhook delivery).
    $stale1 = Order::find($order->id);
    $stale2 = Order::find($order->id);

    $service = app(OrderService::class);
    $service->markPaid($stale1);
    $service->markPaid($stale2);

    $fresh = $order->fresh();
    expect(Shipment::where('order_id', $order->id)->count())->toBe(1)
        ->and($fresh->customer->orders_count)->toBe(1)
        ->and((float) $fresh->customer->total_spent)->toBe((float) $fresh->total);
});

it('is idempotent when cancel is called twice on stale instances of a confirmed order', function () {
    $order = Order::factory()->create(['status' => OrderStatus::AwaitingPayment]);
    $order->customer->update(['orders_count' => 0, 'total_spent' => 0]);

    $service = app(OrderService::class);
    $service->markPaid($order); // confirms it: increments customer stats and creates a shipment

    expect($order->fresh()->customer->orders_count)->toBe(1);

    // Two independent, equally-stale reads of the confirmed row — each stands in for one
    // delivery of a duplicated orders/cancelled webhook, both reaching applyUpdate() ->
    // cancel() with the order still looking Confirmed in memory.
    $stale1 = Order::find($order->id);
    $stale2 = Order::find($order->id);

    $service->cancel($stale1, null);
    $service->cancel($stale2, null);

    $fresh = $order->fresh();
    $cancelledLogs = ActivityLog::where('action', ActivityLogger::ORDER_CANCELLED)
        ->where('subject_type', $order->getMorphClass())
        ->where('subject_id', $order->id)
        ->count();
    $cancelledShipmentEvents = $fresh->shipment->events()->where('status', ShipmentStatus::Cancelled->value)->count();

    expect($fresh->status)->toBe(OrderStatus::Cancelled)
        ->and($cancelledLogs)->toBe(1)
        ->and($cancelledShipmentEvents)->toBe(1)
        ->and($fresh->customer->orders_count)->toBe(0)
        ->and((float) $fresh->customer->total_spent)->toBe(0.0);
});
