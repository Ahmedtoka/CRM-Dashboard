<?php

use App\Bot\Flow\ReplyScheduler;
use App\Bot\Flows\FakeFlowAnswerInterpreter;
use App\Bot\Flows\FlowAnswerInterpreter;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowPrompter;
use App\Bot\Flows\FlowResult;
use App\Bot\Flows\FlowState;
use App\Bot\Flows\Steps\OrderItemsStep;
use App\Bot\Flows\Steps\OrderStep;
use App\Cases\CaseSummary;
use App\Channels\Data\InboundMessageData;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Inbox\InboxIngestor;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Fulfillment;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\SupportCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Event::fake();
    Http::preventStrayRequests();
    config(['crm.drivers.ai' => 'fake']);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => false, 'working_hours' => null]);
    app()->bind(FlowAnswerInterpreter::class, FakeFlowAnswerInterpreter::class);
});

function oarSay(string $text, ?string $payload = null): Conversation
{
    static $n = 0;
    $n++;
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-OAR', 'Mona', 'oar'.$n.'-'.uniqid(), $text, CarbonImmutable::now(), payload: $payload,
    ));

    return Conversation::firstOrFail();
}

function oarTurn(string $text, ?string $payload = null): FlowResult
{
    $c = oarSay($text, $payload);

    return app(FlowEngine::class)->handle($c, app(ReplyScheduler::class)->burst($c));
}

function oarTap(string $value, string $title = 'x', string $step = 'order_items'): FlowResult
{
    return oarTurn($title, "step:return_exchange:{$step}:{$value}");
}

function oarPhoto(): void
{
    $c = Conversation::firstOrFail();
    $m = Message::factory()->for($c, 'conversation')->create(['direction' => 'in', 'sender_type' => 'customer', 'body' => null]);
    MessageAttachment::factory()->create(['message_id' => $m->id]);
    app(FlowEngine::class)->handle($c->fresh(), app(ReplyScheduler::class)->burst($c->fresh()));
}

function oarFlow(): ?array
{
    return FlowState::flow(Conversation::firstOrFail());
}

function oarBot(): Message
{
    return Message::where('sender_type', SenderType::Bot->value)->latest('id')->firstOrFail();
}

/** @return list<string> every bot message body so far */
function oarBotBodies(): array
{
    return Message::where('sender_type', SenderType::Bot->value)->orderBy('id')->pluck('body')->map(fn ($b) => (string) $b)->all();
}

/** Starts the return flow; returns the conversation. */
function oarStart(): Conversation
{
    $c = oarSay('اهلا');
    app(FlowEngine::class)->start($c, 'return_exchange');

    return $c->fresh();
}

/**
 * Order #1047 of سارة أحمد (mobile ending 4567), delivered on 12 September.
 *
 * @param  list<array<string, mixed>>  $items
 */
function oarOrder(array $items = [], array $attrs = []): Order
{
    $order = Order::factory()->create($attrs + [
        'order_number' => '1047',
        'shopify_order_name' => '#1047',
        'shipping_name' => 'سارة أحمد',
        'shipping_phone' => '+201001234567',
        'shipping_address' => '12 شارع التحرير',
        'placed_at' => CarbonImmutable::parse('2026-09-08 10:00', 'Africa/Cairo'),
    ]);

    Fulfillment::factory()->for($order)->create([
        'shipment_status' => 'delivered',
        'shopify_created_at' => CarbonImmutable::parse('2026-09-10 10:00', 'Africa/Cairo'),
        'delivered_at' => CarbonImmutable::parse('2026-09-12 13:00', 'Africa/Cairo'),
    ]);

    foreach ($items ?: [['title' => 'فستان ليلى', 'variant_title' => 'أسود / M', 'qty' => 1, 'price' => 850]] as $item) {
        OrderItem::factory()->for($order)->create($item + ['discount' => 0, 'qty' => 1, 'price' => 500]);
    }

    return $order->fresh('items');
}

/** Order found by number and proven by the last 4 digits: now on the item list. */
function oarVerified(Order $order): void
{
    oarStart();
    oarTurn((string) $order->order_number);
    oarTurn('4567');
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-19 12:00', 'Africa/Cairo'));
});

// ---- 1. Proving ownership --------------------------------------------------------------------

it('asks for the last 4 digits when only the order number is given, and reveals nothing before', function () {
    oarOrder();
    oarStart();

    oarTurn('رقم الأوردر 1047');

    $flow = oarFlow();
    expect($flow['step'])->toBe('order')
        ->and(oarBot()->body)->toBe(OrderStep::VERIFY_TEXT)
        ->and($flow['data'])->not->toHaveKey('order_id')
        ->and($flow['data'])->not->toHaveKey('order_status_line')
        ->and($flow['data']['order_verify']['tries'])->toBe(0);

    foreach (oarBotBodies() as $body) {
        expect($body)->not->toContain('سارة')->not->toContain('التحرير')->not->toContain('فستان')->not->toContain('اتسلم');
    }
});

it('accepts the right last 4 digits (Arabic numerals too) and then lists the items', function () {
    $order = oarOrder();
    oarStart();
    oarTurn('1047');

    oarTurn('٤٥٦٧');

    $flow = oarFlow();
    expect($flow['step'])->toBe('order_items')
        ->and($flow['data']['order_verified'])->toBeTrue()
        ->and($flow['data']['verified_order_ids'])->toBe([$order->id])
        ->and($flow['data']['order_id'])->toBe($order->id)
        ->and($flow['data'])->not->toHaveKey('order_verify')
        ->and(oarBot()->body)->toStartWith('لقيت أوردر #1047 باسم سارة أحمد — اتسلم يوم 12 سبتمبر');
});

it('matches the billing phone and the customer phone too', function () {
    $customer = Customer::factory()->create(['phone' => '01112223344', 'normalized_phone' => '+201112223344']);
    oarOrder([], ['customer_id' => $customer->id, 'shipping_phone' => null, 'billing_phone' => '01229998877']);
    oarStart();
    oarTurn('1047');
    oarTurn('8877');
    expect(oarFlow()['step'])->toBe('order_items');

    Conversation::query()->delete();
    Message::query()->delete();
    oarStart();
    oarTurn('1047');
    oarTurn('آخر أرقام 3344');
    expect(oarFlow()['step'])->toBe('order_items');
});

it('lets her try again after a wrong answer', function () {
    oarOrder();
    oarStart();
    oarTurn('1047');

    oarTurn('1111');
    expect(oarFlow()['step'])->toBe('order')
        ->and(oarBot()->body)->toBe(OrderStep::VERIFY_RETRY_TEXT)
        ->and(oarFlow()['data']['order_verify']['tries'])->toBe(1);

    oarTurn('4567');
    expect(oarFlow()['step'])->toBe('order_items');
});

it('hands over after 2 wrong answers without revealing anything about the order', function () {
    oarOrder();
    $c = oarStart();
    oarTurn('1047');
    oarTurn('1111');
    oarTurn('مش فاكرة');

    $c = $c->fresh();
    expect(oarFlow())->toBeNull()
        ->and($c->handler)->toBe(Handler::Human)
        ->and($c->handover_category)->toBe('order_verification_failed')
        ->and(Message::where('sender_type', SenderType::Bot->value)->where('body', OrderStep::VERIFY_FAILED_TEXT)->exists())->toBeTrue()
        ->and(OrderStep::VERIFY_FAILED_TEXT)->toStartWith('مش قادر أتأكد من الأوردر ده');

    foreach (oarBotBodies() as $body) {
        expect($body)->not->toContain('سارة')->not->toContain('فستان')->not->toContain('التحرير');
    }
});

it('needs no digits when she gives the mobile the order was placed with', function () {
    oarOrder();
    oarStart();

    oarTurn('الاوردر 1047 والموبايل 01001234567');

    expect(oarFlow()['step'])->toBe('order_items')
        ->and(oarFlow()['data']['order_verified'])->toBeTrue()
        ->and(oarBot()->body)->toContain('فستان ليلى');
});

it('needs no digits when the order is found by her mobile alone', function () {
    oarOrder();
    oarStart();

    oarTurn('01001234567');

    expect(oarFlow()['step'])->toBe('order_items')
        ->and(oarBot()->body)->toContain('باسم سارة أحمد');
});

it('needs no digits when the conversation customer is already the order customer', function () {
    $c = oarStart();
    oarOrder([], ['customer_id' => $c->customer_id]);

    oarTurn('1047');

    expect(oarFlow()['step'])->toBe('order_items')
        ->and(oarBot()->body)->toContain('فستان ليلى');
});

it('needs no digits when the conversation customer has the order mobile on her identity', function () {
    $c = oarStart();
    $c->customer->update(['normalized_phone' => '+201001234567']);
    oarOrder();
    oarTurn('1047');
    expect(oarFlow()['step'])->toBe('order_items');
});

it('does not ask again for an order already proven in this flow (summary edit)', function () {
    $order = oarOrder();
    oarVerified($order);
    $c = Conversation::firstOrFail();
    $state = FlowState::flow($c);
    $state['step'] = 'order';
    FlowState::put($c, $state);

    oarTurn('1047');

    expect(oarFlow()['step'])->toBe('order_items');
});

it('keeps the status-only reply of the tracking flow unchanged (no ownership question)', function () {
    $order = oarOrder();
    Shipment::factory()->for($order)->create(['status' => ShipmentStatus::InTransit]);
    $c = oarSay('اهلا');
    app(FlowEngine::class)->start($c, 'order_tracking');

    oarTurn('1047');

    expect(collect(oarBotBodies())->contains(fn ($b) => str_starts_with($b, 'الأوردر رقم #1047')))->toBeTrue()
        ->and(oarBotBodies())->not->toContain(OrderStep::VERIFY_TEXT)
        ->and(oarFlow())->toBeNull();
});

// ---- 2. Listing and picking items --------------------------------------------------------------

it('lists the items numbered with variant, quantity and price, one button each plus "كذا قطعة"', function () {
    $order = oarOrder([
        ['title' => 'فستان ليلى', 'variant_title' => 'أسود / M', 'qty' => 1, 'price' => 850],
        ['title' => 'طرحة شيفون طويلة جدا جدا', 'variant_title' => 'Default Title', 'qty' => 2, 'price' => 150],
        ['title' => 'عباية كتان', 'qty' => 1, 'price' => 1200.5],
    ]);

    oarVerified($order);

    $items = $order->items;
    expect(oarBot()->body)->toBe(implode("\n", [
        'لقيت أوردر #1047 باسم سارة أحمد — اتسلم يوم 12 سبتمبر',
        '1. فستان ليلى — أسود / M × 1 — 850 ج.م',
        '2. طرحة شيفون طويلة جدا جدا × 2 — 150 ج.م',
        '3. عباية كتان × 1 — 1,200.50 ج.م',
        '',
        OrderItemsStep::DEFAULT_TEXT,
    ]))->and(oarBot()->buttons)->toBe([
        ['title' => 'فستان ليلى', 'payload' => "step:return_exchange:order_items:item:{$items[0]->id}"],
        ['title' => 'طرحة شيفون طويلة جدا', 'payload' => "step:return_exchange:order_items:item:{$items[1]->id}"],
        ['title' => 'عباية كتان', 'payload' => "step:return_exchange:order_items:item:{$items[2]->id}"],
        ['title' => 'كذا قطعة', 'payload' => 'step:return_exchange:order_items:multi'],
    ]);

    foreach (oarBot()->buttons as $b) {
        expect(mb_strlen($b['title']))->toBeLessThanOrEqual(20);
    }
});

it('keeps Messenger limits: at most 13 buttons, items past 12 are numbered text only', function () {
    $items = array_map(fn ($n) => ['title' => "قطعة رقم {$n}", 'qty' => 1, 'price' => 100], range(1, 15));
    $order = oarOrder($items);

    oarVerified($order);

    expect(oarBot()->buttons)->toHaveCount(12)
        ->and(oarBot()->body)->toContain('15. قطعة رقم 15 × 1 — 100 ج.م');

    // "14" is not a button: the step reads it as item 14.
    oarTurn('14');
    expect(oarFlow()['data']['selected_items'][0]['title'])->toBe('قطعة رقم 14');
});

it('shows the status and order date when the order is not delivered yet', function () {
    $order = Order::factory()->create([
        'order_number' => '2050', 'shipping_name' => 'منى', 'shipping_phone' => '+201001234567',
        'placed_at' => CarbonImmutable::parse('2026-09-17 10:00', 'Africa/Cairo'),
    ]);
    OrderItem::factory()->for($order)->create(['title' => 'فستان', 'qty' => 1, 'price' => 300, 'discount' => 0]);

    oarStart();
    oarTurn('2050');
    oarTurn('4567');

    expect(oarBot()->body)->toStartWith('لقيت أوردر #2050 باسم منى — اتأكد وجاري تجهيزه (اتطلب يوم 17 سبتمبر)');
});

it('loops tap → "another one?" → tap → "that is all" and moves on with both items', function () {
    $order = oarOrder([
        ['title' => 'فستان ليلى', 'qty' => 1, 'price' => 850],
        ['title' => 'عباية كتان', 'qty' => 1, 'price' => 1200],
        ['title' => 'جيبة', 'qty' => 1, 'price' => 400],
    ]);
    [$a, $b] = [$order->items[0], $order->items[1]];
    oarVerified($order);

    oarTap("item:{$a->id}", 'فستان ليلى');
    expect(oarFlow()['step'])->toBe('order_items')
        ->and(oarBot()->body)->toBe("تمام ✅ ضفت: فستان ليلى × 1\n".OrderItemsStep::MORE_QUESTION)
        ->and(array_column(oarBot()->buttons, 'title'))->toBe(['أيوه', 'لأ كده تمام']);

    oarTap('more', 'أيوه');
    expect(oarBot()->body)->toContain('1. ✅ فستان ليلى')->and(oarBot()->buttons)->toHaveCount(4);

    oarTap("item:{$b->id}", 'عباية كتان');
    oarTap('done', 'لأ كده تمام');

    $flow = oarFlow();
    expect($flow['step'])->toBe('reason')
        ->and(array_column($flow['data']['selected_items'], 'title'))->toBe(['فستان ليلى', 'عباية كتان'])
        ->and($flow['data']['selected_items'][0])->toEqual([
            'line_item_id' => $a->id, 'title' => 'فستان ليلى', 'variant' => null, 'qty' => 1, 'price' => 850.0, 'exchange_only' => false,
        ])
        ->and($flow['data'])->not->toHaveKey('items_pending')
        ->and(oarBot()->body)->toBe('إيه سبب المرتجع؟');
});

it('says so when she taps an item she already picked', function () {
    $order = oarOrder([['title' => 'فستان ليلى', 'qty' => 1], ['title' => 'عباية كتان', 'qty' => 1]]);
    oarVerified($order);
    $a = $order->items[0];

    oarTap("item:{$a->id}");
    oarTap('more');
    oarTap("item:{$a->id}");

    expect(oarBotBodies())->toContain('«فستان ليلى» موجودة في اختياراتك خلاص 🌸')
        ->and(oarFlow()['data']['selected_items'])->toHaveCount(1);
});

it('picks typed numbers: "1 و 3", "1،3" and Arabic digits "١ و ٢"', function (string $typed, array $expected) {
    $order = oarOrder([
        ['title' => 'فستان ليلى', 'qty' => 1],
        ['title' => 'عباية كتان', 'qty' => 1],
        ['title' => 'جيبة', 'qty' => 1],
    ]);
    oarVerified($order);

    oarTurn($typed);

    expect(array_column(oarFlow()['data']['selected_items'], 'title'))->toBe($expected)
        ->and(oarBot()->body)->toEndWith(OrderItemsStep::MORE_QUESTION);
})->with([
    'with و' => ['1 و 3', ['فستان ليلى', 'جيبة']],
    'Arabic comma' => ['1،3', ['فستان ليلى', 'جيبة']],
    'Arabic digits' => ['١ و ٢', ['فستان ليلى', 'عباية كتان']],
    'words' => ['الاولى والتالتة', ['فستان ليلى', 'جيبة']],
]);

it('picks every item with "الكل" and moves on without asking for more', function () {
    $order = oarOrder([['title' => 'فستان ليلى', 'qty' => 1], ['title' => 'عباية كتان', 'qty' => 1]]);
    oarVerified($order);

    oarTurn('الكل');

    expect(oarFlow()['step'])->toBe('reason')
        ->and(oarFlow()['data']['selected_items'])->toHaveCount(2)
        ->and(oarBotBodies())->toContain('تمام ✅ ضفت: فستان ليلى × 1، عباية كتان × 1');
});

it('reads "الاتنين" as both items when there are two', function () {
    $order = oarOrder([['title' => 'فستان ليلى', 'qty' => 1], ['title' => 'عباية كتان', 'qty' => 1]]);
    oarVerified($order);

    oarTurn('الاتنين');

    expect(oarFlow()['step'])->toBe('reason')->and(oarFlow()['data']['selected_items'])->toHaveCount(2);
});

it('matches part of an item title', function () {
    $order = oarOrder([
        ['title' => 'فستان ليلى', 'variant_title' => 'أسود / M', 'qty' => 1],
        ['title' => 'عباية كتان', 'qty' => 1],
    ]);
    oarVerified($order);

    oarTurn('عايزة ارجع العباية الكتان');

    expect(array_column(oarFlow()['data']['selected_items'], 'title'))->toBe(['عباية كتان']);
});

it('asks how many for a line with quantity above 1', function () {
    $order = oarOrder([['title' => 'طرحة شيفون', 'qty' => 3, 'price' => 150], ['title' => 'جيبة', 'qty' => 1]]);
    $item = $order->items[0];
    oarVerified($order);

    oarTap("item:{$item->id}");
    expect(oarBot()->body)->toBe('«طرحة شيفون» — كام قطعة؟ (من 1 لـ 3)')
        ->and(array_column(oarBot()->buttons, 'title'))->toBe(['1', '2', '3']);

    oarTurn('اتنين');
    expect(oarFlow()['data']['selected_items'][0]['qty'])->toBe(2)
        ->and(oarBot()->body)->toBe("تمام ✅ ضفت: طرحة شيفون × 2\n".OrderItemsStep::MORE_QUESTION);
});

it('asks the quantity inside a typed multi-pick, then goes on with the rest', function () {
    $order = oarOrder([['title' => 'طرحة شيفون', 'qty' => 2, 'price' => 150], ['title' => 'جيبة', 'qty' => 1]]);
    oarVerified($order);

    oarTurn('الكل');
    expect(oarBot()->body)->toContain('كام قطعة؟');

    oarTap('qty:2', '2');
    expect(oarFlow()['step'])->toBe('reason')
        ->and(array_column(oarFlow()['data']['selected_items'], 'qty'))->toBe([2, 1]);
});

// ---- Eligibility ------------------------------------------------------------------------------

it('refuses a non-returnable item with the reason and offers another or finish', function () {
    $product = Product::factory()->create(['title' => 'Bonnet', 'product_type' => 'Accessories', 'tags' => ['summer']]);
    $variant = ProductVariant::factory()->for($product)->create(['price' => 100]);
    $order = oarOrder([
        ['title' => 'طرحة ساتان', 'qty' => 1, 'price' => 100, 'variant_id' => $variant->id],
        ['title' => 'فستان ليلى', 'qty' => 1],
    ]);
    oarVerified($order);

    oarTap("item:{$order->items[0]->id}");

    $bodies = oarBotBodies();
    expect($bodies[count($bodies) - 2])->toBe('«طرحة ساتان» من الإكسسوارات ومش بتترجع ولا بتتبدل 🙏')
        ->and(oarBot()->body)->toBe(OrderItemsStep::OTHER_QUESTION)
        ->and(array_column(oarBot()->buttons, 'title'))->toBe(['قطعة تانية', 'لأ كده تمام'])
        ->and(oarFlow()['data']['selected_items'])->toBe([]);

    // Finishing with nothing picked ends the flow politely.
    oarTap('done');
    expect(oarFlow())->toBeNull()->and(oarBot()->body)->toBe(OrderItemsStep::NOTHING_TEXT);
});

it('uses the owner keyword list from the bot settings', function () {
    BotSetting::current()->update(['non_returnable_keywords' => ['جيبة']]);
    $order = oarOrder([['title' => 'جيبة قطيفة', 'qty' => 1], ['title' => 'بونيه قطن', 'qty' => 1]]);
    oarVerified($order);

    oarTurn('1 و 2');

    expect(oarBotBodies())->toContain('«جيبة قطيفة» من الأصناف اللي مش بتترجع ولا بتتبدل 🙏')
        ->and(array_column(oarFlow()['data']['selected_items'], 'title'))->toBe(['بونيه قطن']);
});

it('makes a discounted item exchange only and removes the refund option', function () {
    $order = oarOrder([['title' => 'فستان ليلى', 'qty' => 1, 'price' => 850, 'discount' => 100]]);
    oarVerified($order);

    oarTap("item:{$order->items[0]->id}");

    expect(oarBotBodies())->toContain('«فستان ليلى» عليها خصم، فمتاحة للاستبدال بس مش استرجاع الفلوس 🌸')
        ->and(oarFlow()['step'])->toBe('reason')
        ->and(oarFlow()['data']['selected_items'][0]['exchange_only'])->toBeTrue();

    oarTap('size', 'المقاس مش مظبوط', 'reason');
    expect(oarFlow()['step'])->toBe('request')
        ->and(oarBot()->body)->toBe(FlowPrompter::EXCHANGE_ONLY_NOTE."\nحضرتك عايزة استرجاع المبلغ ولا استبدال؟")
        ->and(array_column(oarBot()->buttons, 'payload'))->not->toContain('step:return_exchange:request:refund')
        ->and(array_column(oarBot()->buttons, 'payload'))->toContain('step:return_exchange:request:exchange');

    // A typed or stale refund answer is not accepted.
    oarTurn('استرجاع المبلغ');
    expect(oarFlow()['step'])->toBe('request');
    oarTap('refund', 'استرجاع المبلغ', 'request');
    expect(oarFlow()['step'])->toBe('request');

    oarTap('exchange', 'استبدال', 'request');
    expect(oarFlow()['step'])->toBe('product_photo')->and(oarFlow()['data']['request'])->toBe('exchange');
});

it('treats a variant sold under its compare-at price as discounted, and keeps refund for a mixed pick', function () {
    $variant = ProductVariant::factory()->create(['price' => 400, 'compare_at_price' => 600]);
    $order = oarOrder([
        ['title' => 'فستان سهرة', 'qty' => 1, 'price' => 400, 'variant_id' => $variant->id],
        ['title' => 'عباية كتان', 'qty' => 1, 'price' => 900],
    ]);
    oarVerified($order);

    oarTurn('الكل');
    expect(array_column(oarFlow()['data']['selected_items'], 'exchange_only'))->toBe([true, false]);

    oarTap('size', 'المقاس مش مظبوط', 'reason');
    expect(array_column(oarBot()->buttons, 'payload'))->toContain('step:return_exchange:request:refund');
});

it('does not add an item past 14 days from delivery and offers a person', function () {
    $order = oarOrder();
    $order->fulfillments()->update(['delivered_at' => CarbonImmutable::now()->subDays(20)]);
    oarVerified($order);

    oarTap("item:{$order->items[0]->id}");

    expect(oarBot()->body)->toBe("«فستان ليلى (أسود / M)» عدّى على استلامها أكتر من 14 يوم، والمرتجع والاستبدال عندنا خلال 14 يوم من الاستلام بس 🙏\nتحبي أحوّلك لحد من الفريق؟")
        ->and(oarBot()->buttons)->toBe([
            ['title' => 'كلم موظف', 'payload' => 'handover'],
            ['title' => 'لأ كده تمام', 'payload' => 'step:return_exchange:order_items:done'],
        ])
        ->and(oarFlow()['data']['selected_items'])->toBe([]);

    oarTurn('كلم موظف', 'handover');
    expect(Conversation::first()->handler)->toBe(Handler::Human);
});

it('counts the window from fulfillment + 3 days without a delivery date, and allows an unknown one', function () {
    $late = oarOrder();
    $late->fulfillments()->update(['delivered_at' => null, 'shipment_status' => 'in_transit', 'shopify_created_at' => CarbonImmutable::now()->subDays(18)]);
    oarVerified($late);
    oarTap("item:{$late->items[0]->id}");
    expect(oarFlow()['data']['selected_items'])->toBe([]);

    Conversation::query()->delete();
    Message::query()->delete();
    Order::query()->delete();

    $unknown = oarOrder();
    $unknown->fulfillments()->delete();
    oarVerified($unknown);
    oarTap("item:{$unknown->items[0]->id}");
    expect(oarFlow()['step'])->toBe('reason');
});

it('asks for the item name when the order has no synced items', function () {
    $order = oarOrder();
    $order->items()->delete();
    oarVerified($order);

    expect(oarFlow()['step'])->toBe('order_items')
        ->and(oarBot()->body)->toBe(OrderItemsStep::FALLBACK_TEXT);

    oarTurn('الفستان الاسود الطويل');
    expect(oarFlow()['step'])->toBe('reason')
        ->and(oarFlow()['data']['selected_items'])->toBe([
            ['line_item_id' => null, 'title' => 'الفستان الاسود الطويل', 'variant' => null, 'qty' => 1, 'price' => null, 'exchange_only' => false],
        ]);
});

it('asks for the item name when the order was not found (nothing listed)', function () {
    oarStart();
    oarTurn('999999');
    oarTurn('مش فاكرة');

    expect(oarFlow()['step'])->toBe('order_items')
        ->and(oarBot()->body)->toBe(OrderItemsStep::FALLBACK_TEXT);

    oarTurn('بونيه');
    expect(oarFlow()['step'])->toBe('order_items')
        ->and(oarBot()->body)->toContain('مش بتترجع');
});

// ---- 3. Case, summary and the whole flow ---------------------------------------------------------

it('runs the whole return flow end to end and records the items on the case', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $order = oarOrder([
        ['title' => 'فستان ليلى', 'variant_title' => 'أسود / M', 'qty' => 1, 'price' => 850, 'discount' => 50],
        ['title' => 'طرحة شيفون', 'qty' => 2, 'price' => 150],
        ['title' => 'بونيه قطن', 'qty' => 1, 'price' => 60],
    ]);

    oarStart();
    oarTurn('1047');
    oarTurn('4567');
    oarTurn('1 و 2 و 3');                     // 1 → exchange only, 2 → how many?, 3 → refused
    oarTap('qty:1', '1');
    // Nothing else can be picked (the bonnet is refused), so the flow moves on by itself.
    expect(oarBotBodies())->toContain('«بونيه قطن» من الأصناف اللي مش بتترجع ولا بتتبدل 🙏')
        ->and(oarFlow()['step'])->toBe('reason');
    oarTap('defective', 'بايظ / فيه عيب', 'reason');
    oarTap('exchange', 'استبدال', 'request');
    oarPhoto();
    oarPhoto();

    $summary = oarBot();
    expect(oarFlow()['step'])->toBe('summary')
        ->and($summary->body)->toContain('• القطع: فستان ليلى (أسود / M) × 1 — استبدال بس، طرحة شيفون × 1');

    oarTap('confirm', 'تمام، سجل', 'summary');

    $case = SupportCase::sole();
    expect($case->order_id)->toBe($order->id)
        ->and($case->data['order_verified'])->toBeTrue()
        ->and($case->data)->not->toHaveKey('order_verify')
        ->and($case->data)->not->toHaveKey('verified_order_ids')
        ->and($case->data)->not->toHaveKey('items_pending')
        ->and($case->data['selected_items'])->toEqual([
            ['line_item_id' => $order->items[0]->id, 'title' => 'فستان ليلى', 'variant' => 'أسود / M', 'qty' => 1, 'price' => 850.0, 'exchange_only' => true],
            ['line_item_id' => $order->items[1]->id, 'title' => 'طرحة شيفون', 'variant' => null, 'qty' => 1, 'price' => 150.0, 'exchange_only' => false],
        ]);

    $items = collect(CaseSummary::sections($case))->firstWhere('key', 'items');
    expect($items['title'])->toBe('القطع المطلوبة')
        ->and($items['lines'])->toBe(['فستان ليلى — أسود / M × 1 — 850 ج.م (استبدال بس)', 'طرحة شيفون × 1 — 150 ج.م'])
        ->and($case->summary)->toContain('🛍️ القطع المطلوبة');

    $this->actingAs($sup)->getJson("/cases/{$case->id}")
        ->assertOk()
        ->assertJsonPath('data.items.0.title', 'فستان ليلى')
        ->assertJsonPath('data.items.0.exchange_only', true)
        ->assertJsonPath('data.items.1.qty', 1);
});

it('saves the non-returnable keywords from the bot settings page', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    expect(BotSetting::current()->nonReturnableKeywords())->toBe(BotSetting::DEFAULT_NON_RETURNABLE_KEYWORDS);

    $this->actingAs($admin)->putJson('/settings/bot', ['non_returnable_keywords' => ['بونيه', ' شراب ']])->assertOk();
    expect(BotSetting::current()->nonReturnableKeywords())->toBe(['بونيه', 'شراب']);

    $this->actingAs($admin)->putJson('/settings/bot', ['non_returnable_keywords' => [str_repeat('x', 101)]])
        ->assertStatus(422)->assertJsonValidationErrorFor('non_returnable_keywords.0');
});
