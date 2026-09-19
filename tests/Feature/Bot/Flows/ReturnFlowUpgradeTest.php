<?php

use App\Bot\Flows\FlowDefinition;
use App\Bot\Flows\FlowDefinitions;
use App\Bot\Flows\FlowDrafts;
use App\Bot\Flows\FlowStepCatalog;
use App\Bot\Flows\ReturnFlowUpgrade;
use App\Bot\Flows\Returns\ItemSelection;
use App\Models\BotFlow;
use App\Models\Order;
use App\Models\User;
use App\Shopify\Sync\Mappers\OrderMapper;
use Illuminate\Support\Facades\Event;

/** Puts the live return flow back to the 2026-09-17 seed with one published version (a pre-upgrade install). */
function rfuLegacyInstall(): BotFlow
{
    $flow = BotFlow::where('key', 'return_exchange')->firstOrFail();
    $flow->versions()->delete();
    $flow->update(['definition' => ReturnFlowUpgrade::legacyDefinition()]);
    $flow->versions()->create(['version' => 1, 'status' => 'published', 'definition' => ReturnFlowUpgrade::legacyDefinition(), 'published_at' => now()]);

    return $flow->fresh();
}

function rfuMigrate(): void
{
    (require database_path('migrations/2026_09_19_300010_publish_owner_return_exchange_flow.php'))->up();
}

it('seeds the owner flow for return_exchange, valid and without warnings', function () {
    $def = BotFlow::where('key', 'return_exchange')->firstOrFail()->definition;

    expect(ReturnFlowUpgrade::same($def, ReturnFlowUpgrade::definition()))->toBeTrue()
        ->and(FlowDefinitions::all()['return_exchange']['definition'])->toBe(ReturnFlowUpgrade::definition())
        ->and($def['steps']['order']['verify_owner'])->toBeTrue()
        ->and($def['steps']['kind']['text'])->toBe(ReturnFlowUpgrade::GREETING_TEXT)
        ->and($def['steps']['exchange_product']['type'])->toBe('product_link')
        ->and($def['steps']['record_return']['case_type'])->toBe('return')
        ->and($def['steps']['record_exchange']['case_type'])->toBe('exchange')
        ->and(FlowDefinition::validate($def))->toBe([])
        ->and(FlowDefinition::validateReferences($def))->toBe([])
        ->and(FlowDefinition::warnings($def))->toBe([])
        ->and(FlowDefinition::validate(ReturnFlowUpgrade::orderAwareDefinition()))->toBe([]);
});

it('publishes the owner flow over the old seed as a new version, the old one archived', function () {
    $flow = rfuLegacyInstall();

    rfuMigrate();

    $flow->refresh();
    $published = $flow->versions()->where('status', 'published')->sole();
    expect($flow->definition)->toBe(ReturnFlowUpgrade::definition())
        ->and($published->version)->toBe(2)
        ->and($published->note)->toBe(ReturnFlowUpgrade::NOTE)
        ->and($published->definition)->toBe(ReturnFlowUpgrade::definition())
        ->and($flow->versions()->where('status', 'archived')->sole()->definition)->toBe(ReturnFlowUpgrade::legacyDefinition())
        ->and($flow->draft()->exists())->toBeFalse();

    // Idempotent: running it again changes nothing.
    rfuMigrate();
    expect($flow->versions()->count())->toBe(2)->and($flow->fresh()->definition)->toBe(ReturnFlowUpgrade::definition());
});

it('replaces the first order-aware version and an owner edit alike, and archives her draft', function () {
    $flow = rfuLegacyInstall();
    $edited = ReturnFlowUpgrade::orderAwareDefinition();
    $edited['steps']['reason']['text'] = 'إيه اللي حصل؟';
    $flow->update(['definition' => $edited]);
    $flow->versions()->where('status', 'published')->update(['definition' => json_encode($edited, JSON_UNESCAPED_UNICODE)]);
    $mine = ReturnFlowUpgrade::orderAwareDefinition();
    $mine['steps']['summary']['text'] = 'مسودتي';
    $draft = $flow->versions()->create(['version' => 2, 'status' => 'draft', 'definition' => $mine]);

    rfuMigrate();

    $flow->refresh();
    expect($flow->definition)->toBe(ReturnFlowUpgrade::definition())
        ->and($flow->draft()->exists())->toBeFalse()
        ->and($draft->fresh()->status)->toBe('archived')
        ->and($draft->fresh()->definition['steps']['summary']['text'])->toBe('مسودتي')
        ->and($flow->versions()->where('status', 'archived')->count())->toBe(2)
        ->and($flow->versions()->where('status', 'published')->sole()->version)->toBe(3)
        ->and(app(FlowDrafts::class)->draftFor($flow))->toBe(ReturnFlowUpgrade::definition());

    // The old versions can still be restored from the designer's history.
    $old = $flow->versions()->where('version', 1)->sole();
    $restored = app(FlowDrafts::class)->restore($old, User::factory()->create());
    expect($restored->definition['steps']['reason']['text'])->toBe('إيه اللي حصل؟')
        ->and($flow->fresh()->definition['steps']['reason']['text'])->toBe('إيه اللي حصل؟');

    // Idempotent only while the owner flow is live: a restore is the owner's choice, but re-running
    // on a flow that is already the owner flow does nothing.
    $flow->fresh()->update(['definition' => ReturnFlowUpgrade::definition()]);
    $count = $flow->versions()->count();
    rfuMigrate();
    expect($flow->versions()->count())->toBe($count);
});

it('keeps the superseded first upgrade migration a no-op', function () {
    $flow = rfuLegacyInstall();

    (require database_path('migrations/2026_09_19_200020_add_order_items_to_return_flow.php'))->up();

    expect($flow->fresh()->definition)->toBe(ReturnFlowUpgrade::legacyDefinition())
        ->and($flow->versions()->count())->toBe(1);
});

// ---- designer validation of the new type -------------------------------------------------------

it('validates the order_items step type for the designer', function () {
    $base = fn (array $items) => ['start' => 'order', 'steps' => [
        'order' => ['type' => 'order', 'field' => 'order', 'verify_owner' => true, 'next' => 'items'],
        'items' => $items,
    ]];

    expect(FlowDefinition::validate($base(['type' => 'order_items', 'next' => 'end'])))->toBe([])
        ->and(FlowDefinition::validate($base(['type' => 'order_items', 'text' => 'اختاري', 'next' => 'end'])))->toBe([])
        ->and(FlowDefinition::validate($base(['type' => 'order_items'])))->toBe(["step 'items' next must be a non-empty string"])
        ->and(FlowDefinition::validate($base(['type' => 'order_items', 'next' => 'nowhere'])))->toBe(["step 'items' next references unknown step 'nowhere'"])
        ->and(FlowDefinition::validate($base(['type' => 'order_items', 'text' => ['x'], 'next' => 'end'])))->toBe(["step 'items' 'text' must be a string"]);

    $noProof = $base(['type' => 'order_items', 'next' => 'end']);
    $noProof['steps']['order']['verify_owner'] = 'yes';
    expect(FlowDefinition::validate($noProof))->toBe(["step 'order' 'verify_owner' must be true or false"]);

    unset($noProof['steps']['order']['verify_owner']);
    expect(FlowDefinition::warnings($noProof))->toHaveCount(1)
        ->and(FlowDefinition::warnings($noProof)[0])->toStartWith('الخطوة items محتاجة');

    expect(FlowStepCatalog::all()['order_items'])->toBe([
        'label_ar' => 'اختيار قطع من الأوردر', 'icon' => 'PackageOpen', 'color' => 'amber', 'fields' => ['text'], 'options' => 'none', 'has_next' => true,
    ])->and(FlowStepCatalog::all()['order']['fields'])->toContain('verify_owner')
        ->and(array_keys(FlowStepCatalog::all()))->toBe(FlowDefinition::TYPES);
});

// ---- selection parsing -----------------------------------------------------------------------------

it('parses item picks and quantities', function () {
    $s = app(ItemSelection::class);

    expect($s->positions('1 و 3', 3))->toBe([1, 3])
        ->and($s->positions('١،٣', 3))->toBe([1, 3])
        ->and($s->positions('و2', 3))->toBe([2])
        ->and($s->positions('الكل', 5))->toBe(ItemSelection::ALL)
        ->and($s->positions('كله', 5))->toBe(ItemSelection::ALL)
        ->and($s->positions('الاتنين', 2))->toBe(ItemSelection::ALL)
        ->and($s->positions('الاتنين', 3))->toBeNull()
        ->and($s->positions('التانية', 3))->toBe([2])
        ->and($s->positions('1047', 3))->toBeNull()
        ->and($s->positions('عايزة قطعة تانية', 3))->toBeNull()
        ->and($s->quantity('٣', 3))->toBe(3)
        ->and($s->quantity('كلهم', 4))->toBe(4)
        ->and($s->quantity('5', 3))->toBeNull()
        ->and($s->matchTitle('الفستان', [1 => 'فستان ليلى', 2 => 'عباية كتان']))->toBe(1)
        ->and($s->matchTitle('الاسود', [1 => 'فستان ليلى اسود / M', 2 => 'فستان سهرة احمر']))->toBe(1)
        ->and($s->matchTitle('فستان', [1 => 'فستان ليلى', 2 => 'فستان سهرة']))->toBeNull();
});

// ---- Shopify data the picker needs --------------------------------------------------------------

it('syncs the variant title, billing phone and delivery time from Shopify', function () {
    Event::fake();
    $mapper = app(OrderMapper::class);

    $mapper->upsert([
        'id' => 'gid://shopify/Order/9001', 'name' => '#9001', 'updatedAt' => '2026-09-10T10:00:00Z', 'currencyCode' => 'EGP',
        'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'FULFILLED',
        'shippingAddress' => ['name' => 'سارة', 'phone' => '01001234567'],
        'billingAddress' => ['name' => 'سارة', 'phone' => '01229998877'],
        'lineItems' => ['nodes' => [[
            'id' => 'gid://shopify/LineItem/1', 'title' => 'فستان ليلى', 'variantTitle' => 'أسود / M', 'quantity' => 1,
            'originalUnitPriceSet' => ['shopMoney' => ['amount' => '850.0', 'currencyCode' => 'EGP']],
            'discountAllocations' => [['allocatedAmountSet' => ['shopMoney' => ['amount' => '50.0', 'currencyCode' => 'EGP']]]],
        ]]],
    ]);

    $order = Order::where('shopify_order_id', '9001')->firstOrFail();
    expect($order->billing_phone)->toBe('01229998877')
        ->and($order->items->sole()->variant_title)->toBe('أسود / M')
        ->and((float) $order->items->sole()->discount)->toBe(50.0);

    $mapper->applyFulfillment([
        'id' => 'gid://shopify/Fulfillment/5', 'order' => ['id' => 'gid://shopify/Order/9001'], 'status' => 'SUCCESS',
        'displayStatus' => 'DELIVERED', 'createdAt' => '2026-09-10T10:00:00Z', 'updatedAt' => '2026-09-12T11:00:00Z', 'deliveredAt' => '2026-09-12T10:30:00Z',
    ]);
    expect($order->fulfillments()->sole()->delivered_at->toIso8601String())->toBe('2026-09-12T10:30:00+00:00');

    // REST: a "delivered" shipment status dates the delivery by the payload's updated_at.
    $mapper->applyFulfillment([
        'id' => 6, 'order_id' => 9001, 'status' => 'success', 'shipment_status' => 'delivered',
        'created_at' => '2026-09-10T10:00:00Z', 'updated_at' => '2026-09-13T09:00:00Z',
    ]);
    expect($order->fulfillments()->where('shopify_fulfillment_id', '6')->sole()->delivered_at->toIso8601String())->toBe('2026-09-13T09:00:00+00:00');
});
