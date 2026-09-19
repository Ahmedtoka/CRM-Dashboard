<?php

use App\Bot\Flow\Orders\DeliveryEstimate;
use App\Bot\Flow\ReplyScheduler;
use App\Bot\Flows\ClaudeFlowAnswerInterpreter;
use App\Bot\Flows\FakeFlowAnswerInterpreter;
use App\Bot\Flows\FlowAnswerInterpreter;
use App\Bot\Flows\FlowDefinition;
use App\Bot\Flows\FlowDefinitions;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowPrompter;
use App\Bot\Flows\FlowResult;
use App\Bot\Flows\FlowState;
use App\Bot\Flows\FlowStepCatalog;
use App\Bot\Flows\Returns\RemoteProductLookup;
use App\Bot\Flows\Sandbox\FlowSandbox;
use App\Bot\Flows\Steps\OrderStep;
use App\Bot\Flows\Steps\StatusStep;
use App\Bot\Flows\TrackingFlowUpgrade;
use App\Channels\Data\InboundMessageData;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Inbox\InboxIngestor;
use App\Models\BotFlow;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Fulfillment;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\SupportCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

// The owner's order-tracking flow of 2026-09-19 (TrackingFlowUpgrade::definition()), end to end.
// "Today" is Saturday 19 September 2026, noon in Cairo.

beforeEach(function () {
    Event::fake();
    Http::preventStrayRequests();
    config(['crm.drivers.ai' => 'fake']);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => false, 'working_hours' => null]);
    app()->bind(FlowAnswerInterpreter::class, FakeFlowAnswerInterpreter::class);
    app()->instance(RemoteProductLookup::class, new class implements RemoteProductLookup
    {
        public function byHandle(string $handle): ?Product
        {
            return null;
        }
    });
    $this->travelTo(CarbonImmutable::parse('2026-09-19 12:00', 'Africa/Cairo'));
});

function otfSay(string $text, ?string $payload = null): Conversation
{
    static $n = 0;
    $n++;
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-OTF', 'Mona', 'otf'.$n.'-'.uniqid(), $text, CarbonImmutable::now(), payload: $payload,
    ));

    return Conversation::firstOrFail();
}

function otfTurn(string $text, ?string $payload = null): FlowResult
{
    $c = otfSay($text, $payload);

    return app(FlowEngine::class)->handle($c, app(ReplyScheduler::class)->burst($c));
}

function otfTap(string $step, string $value, string $title = 'x', string $flow = 'order_tracking'): FlowResult
{
    return otfTurn($title, "step:{$flow}:{$step}:{$value}");
}

function otfFlow(): ?array
{
    return FlowState::flow(Conversation::firstOrFail());
}

function otfBot(): Message
{
    return Message::where('sender_type', SenderType::Bot->value)->latest('id')->firstOrFail();
}

/** @return list<string> */
function otfBodies(): array
{
    return Message::where('sender_type', SenderType::Bot->value)->orderBy('id')->pluck('body')->map(fn ($b) => (string) $b)->all();
}

/** @return list<string> */
function otfButtons(): array
{
    return array_column((array) otfBot()->buttons, 'title');
}

/** Order #1047 of سارة أحمد (mobile ending 4567), 3 pieces, shipped to Cairo. */
function otfOrder(array $attrs = [], ?array $fulfillment = null): Order
{
    $order = Order::factory()->create($attrs + [
        'order_number' => '1047',
        'shopify_order_name' => '#1047',
        'shipping_name' => 'سارة أحمد',
        'shipping_phone' => '+201001234567',
        'shipping_city' => 'مدينة نصر',
        'shipping_province_code' => 'C',
        'shipping_address' => '12 شارع التحرير',
        'fulfillment_status' => null,
        'placed_at' => CarbonImmutable::parse('2026-09-17 10:00', 'Africa/Cairo'),
    ]);

    OrderItem::factory()->for($order)->create(['title' => 'فستان ليلى', 'qty' => 1, 'price' => 850, 'discount' => 0]);
    OrderItem::factory()->for($order)->create(['title' => 'طرحة شيفون', 'qty' => 2, 'price' => 150, 'discount' => 0]);

    if ($fulfillment !== null) {
        Fulfillment::factory()->for($order)->create($fulfillment);
    }

    return $order->fresh();
}

function otfStart(): void
{
    $c = otfSay('اهلا');
    expect(app(FlowEngine::class)->runPayload($c, 'flow:order_tracking'))->toBeTrue();
}

/** The flow started, the order found by her mobile: the status card is on screen. */
function otfCard(): void
{
    otfStart();
    otfTurn('01001234567');
    expect(otfFlow()['step'])->toBe('status');
}

// ---- finding the order ------------------------------------------------------------------------

it('asks for the order number or mobile from the main-menu button', function () {
    $c = otfSay('اهلا');
    app(FlowEngine::class)->start($c, 'main_menu');
    otfTurn('متابعة أوردر', 'flow:order_tracking');

    expect(otfFlow()['step'])->toBe('order')
        ->and(otfBot()->body)->toBe('ممكن رقم الأوردر أو رقم الموبايل اللي اتعمل بيه الأوردر؟ 🌸');
});

it('shows the status card straight away for her mobile, with the window and the tracking link', function () {
    $order = otfOrder([], ['shipment_status' => 'in_transit', 'tracking_url' => 'https://track.example/1047', 'tracking_number' => 'TRK1047', 'shopify_created_at' => now()->subDay()]);
    Shipment::factory()->for($order)->create(['status' => ShipmentStatus::InTransit]);
    otfCard();

    expect(otfBot()->body)->toBe(implode("\n", [
        'أهلاً يا سارة 🌸 أوردر #1047 (اتطلب يوم الخميس 17/9 — 3 قطع)',
        '📦 الحالة: اتشحن ومع شركة الشحن',
        '🚚 متوقع يوصل: الإثنين 21/9 لحد الأربعاء 23/9',
        '🔗 تتبع الشحنة: https://track.example/1047',
    ]))
        ->and(otfButtons())->toBe(['تمام شكرًا', 'الأوردر اتأخر', 'عايزة ألغي/أعدل', 'كلم موظف'])
        ->and(otfBodies())->not->toContain(OrderStep::VERIFY_TEXT)
        ->and(SupportCase::count())->toBe(0);
});

it('uses the tracking number when there is no link, and 5-7 working days outside the main cities', function () {
    otfOrder(['shipping_province_code' => null, 'shipping_city' => 'المنصورة'], ['shipment_status' => null, 'tracking_url' => null, 'tracking_number' => 'BOSTA-77']);
    otfCard();

    // Thursday 17/9 + 5 working days (Friday skipped) = Wednesday 23/9 … + 7 = Saturday 26/9.
    expect(otfBot()->body)->toContain('🚚 متوقع يوصل: الأربعاء 23/9 لحد السبت 26/9')
        ->and(otfBot()->body)->toContain('🔗 تتبع الشحنة: BOSTA-77');
});

it('asks for the last 4 digits for an order number, then shows the card', function () {
    otfOrder();
    otfStart();

    otfTurn('1047');
    expect(otfBot()->body)->toBe(OrderStep::VERIFY_TEXT)
        ->and(implode("\n", otfBodies()))->not->toContain('سارة')->not->toContain('اتطلب');

    otfTurn('4567');

    expect(otfFlow()['step'])->toBe('status')
        ->and(otfBot()->body)->toStartWith('أهلاً يا سارة 🌸 أوردر #1047 (اتطلب يوم الخميس 17/9 — 3 قطع)')
        ->and(otfBot()->body)->toContain('📦 الحالة: اتأكد وجاري تجهيزه')
        ->and(otfBot()->body)->not->toContain('🔗');
});

it('hands over after 2 wrong digits, revealing nothing about the order', function () {
    otfOrder([], ['tracking_url' => 'https://track.example/1047']);
    otfStart();
    otfTurn('1047');
    otfTurn('1111');
    otfTurn('2222');

    expect(otfFlow())->toBeNull()
        ->and(Conversation::first()->handler)->toBe(Handler::Human)
        // The refusal, then the handover's working-hours reply (flow 7; no hours set here).
        ->and(array_slice(otfBodies(), -2))->toBe([OrderStep::VERIFY_FAILED_TEXT, 'تمام ✅ حولتك لحد من الفريق، هيرد عليكي في أقرب وقت 🌸'])
        ->and(implode("\n", otfBodies()))->not->toContain('سارة')->not->toContain('track.example')->not->toContain('الحالة')
        ->and(SupportCase::count())->toBe(0);
});

it('lists several open orders on her mobile as buttons and shows the one she taps', function () {
    $first = otfOrder(['order_number' => '1111', 'shopify_order_name' => '#1111', 'placed_at' => CarbonImmutable::parse('2026-09-10 10:00', 'Africa/Cairo')]);
    $second = otfOrder(['order_number' => '2222', 'shopify_order_name' => '#2222', 'placed_at' => CarbonImmutable::parse('2026-09-18 10:00', 'Africa/Cairo')]);
    $stranger = otfOrder(['order_number' => '3333', 'shopify_order_name' => '#3333', 'shipping_phone' => '+201119998877']);
    otfStart();

    otfTurn('01001234567');
    expect(otfFlow()['step'])->toBe('order')
        ->and(otfBot()->body)->toStartWith('لقيت أكتر من أوردر: #')
        ->and(otfButtons())->toBe(['#2222 · 18/9', '#1111 · 10/9'])
        ->and(array_column((array) otfBot()->buttons, 'payload'))->toBe(["step:order_tracking:order:pick:{$second->id}", "step:order_tracking:order:pick:{$first->id}"]);

    // A forged tap on an order that was not offered reveals nothing.
    otfTap('order', 'pick:'.$stranger->id, '#3333');
    expect(otfFlow()['step'])->toBe('order')
        ->and(implode("\n", otfBodies()))->not->toContain('#3333 (');

    otfTap('order', 'pick:'.$first->id, '#1111 · 10/9');
    expect(otfFlow()['step'])->toBe('status')
        ->and(otfFlow()['data']['order_id'])->toBe($first->id)
        ->and(otfBot()->body)->toStartWith('أهلاً يا سارة 🌸 أوردر #1111 (اتطلب يوم الخميس 10/9 — 3 قطع)');
});

it('offers at most 13 orders as buttons', function () {
    foreach (range(1, 15) as $i) {
        Order::factory()->create(['order_number' => (string) (5000 + $i), 'shipping_phone' => '+201001234567', 'fulfillment_status' => null, 'placed_at' => now()->subDays($i)]);
    }
    otfStart();

    otfTurn('01001234567');
    expect(otfButtons())->toHaveCount(OrderStep::MAX_ORDER_BUTTONS);
});

it('asks once more when nothing is found, then offers a person or the main menu', function () {
    otfStart();

    otfTurn('999999');
    expect(otfBot()->body)->toBe('مش لاقية أوردر بالبيانات دي 🌸 ممكن تتأكدي من الرقم؟');

    otfTurn('888888');
    expect(otfFlow()['step'])->toBe('not_found')
        ->and(otfBot()->body)->toBe(TrackingFlowUpgrade::NOT_FOUND_TEXT)
        ->and(otfButtons())->toBe(['كلم موظف', 'القائمة الرئيسية']);

    otfTap('not_found', 'agent', 'كلم موظف');
    expect(otfFlow())->toBeNull()
        ->and(Conversation::first()->handler)->toBe(Handler::Human)
        ->and(SupportCase::count())->toBe(0);
});

// ---- the status card's buttons --------------------------------------------------------------------

it('records nothing for plain tracking, even for an order past its window, and thanks her', function () {
    // The observed bug: the old status step opened a delivery follow-up and promised a call by itself.
    otfOrder(['placed_at' => CarbonImmutable::parse('2026-09-01 10:00', 'Africa/Cairo')]);
    otfCard();

    expect(SupportCase::count())->toBe(0)
        ->and(implode("\n", otfBodies()))->not->toContain('هيتواصل معاكي')
        ->and(otfFlow()['data']['order_late'])->toBe('overdue');

    otfTap('status', 'thanks', 'تمام شكرًا');

    expect(otfBot()->body)->toBe('العفو 🌸 لو احتجتي أي حاجة أنا موجودة')
        ->and(otfFlow())->toBeNull()
        ->and(Conversation::first()->handler)->toBe(Handler::Bot)
        ->and(SupportCase::count())->toBe(0);
});

it('says the delivery «was expected» once the window has passed', function () {
    otfOrder(['placed_at' => CarbonImmutable::parse('2026-09-01 10:00', 'Africa/Cairo')]);
    otfCard();

    expect(otfBot()->body)->toContain("\n🚚 كان متوقع يوصل: ")
        ->not->toContain("\n🚚 متوقع يوصل: ")
        ->and(otfFlow()['data']['order_eta_passed'])->toBeTrue();
});

it('keeps «متوقع يوصل» while the window is still ahead', function () {
    otfOrder();
    otfCard();

    expect(otfBot()->body)->toContain("\n🚚 متوقع يوصل: ")->not->toContain('كان متوقع')
        ->and(otfFlow()['data'])->not->toHaveKey('order_eta_passed');
});

it('understands a typed thanks on the card', function () {
    otfOrder();
    otfCard();

    otfTurn('تمام شكرا');

    expect(otfBot()->body)->toBe(TrackingFlowUpgrade::THANKS_TEXT)->and(otfFlow())->toBeNull();
});

it('records a delivery follow-up when she says it is late and the window has passed', function () {
    $order = otfOrder(['placed_at' => CarbonImmutable::parse('2026-09-01 10:00', 'Africa/Cairo')]);
    otfCard();

    otfTap('status', 'late', 'الأوردر اتأخر');

    $case = SupportCase::sole();
    expect($case->type)->toBe('delivery_followup')
        ->and($case->order_id)->toBe($order->id)
        ->and($case->data['order_status_key'])->toBe('confirmed')
        ->and($case->data['order_status'])->toBe('اتأكد وجاري تجهيزه')
        ->and($case->data)->not->toHaveKey('order_choices')
        ->and(otfBot()->body)->toBe('سجلت طلب متابعة للأوردر #1047 🌸 الفريق هيتابع مع شركة الشحن ويرد عليكي في أقرب وقت')
        ->and(otfFlow())->toBeNull()
        ->and(Conversation::first()->handler)->toBe(Handler::Bot);
});

it('records a follow-up for a failed delivery attempt even inside the window', function () {
    $order = otfOrder();
    Shipment::factory()->for($order)->create(['status' => ShipmentStatus::FailedAttempt]);
    otfCard();

    expect(otfBot()->body)->toContain('📦 الحالة: المندوب حاول يسلمه ومعرفش')
        ->and(SupportCase::count())->toBe(0);

    otfTap('status', 'late', 'الأوردر اتأخر');

    expect(SupportCase::sole()->type)->toBe('delivery_followup')
        ->and(otfBot()->body)->toBe('سجلت طلب متابعة للأوردر #1047 🌸 الفريق هيتابع مع شركة الشحن ويرد عليكي في أقرب وقت');
});

it('reassures her when the order is still inside its window, then offers thanks or a person', function () {
    otfOrder();
    otfCard();

    otfTap('status', 'late', 'الأوردر اتأخر');

    expect(otfFlow()['step'])->toBe('late_ok')
        ->and(otfBot()->body)->toBe('الأوردر لسه في معاده 🌸 متوقع يوصل من الإثنين 21/9 لحد الأربعاء 23/9، ولو اتأخر عن كده ابعتيلي وهتابعه فورًا')
        ->and(otfButtons())->toBe(['تمام شكرًا', 'كلم موظف', 'القائمة الرئيسية'])
        ->and(SupportCase::count())->toBe(0);

    otfTap('late_ok', 'agent', 'كلم موظف');

    expect(Conversation::first()->handler)->toBe(Handler::Human)
        ->and(otfFlow())->toBeNull()
        ->and(SupportCase::count())->toBe(0);
});

it('hands her to a person from the card', function () {
    otfOrder();
    otfCard();

    otfTap('status', 'agent', 'كلم موظف');

    expect(Conversation::first()->handler)->toBe(Handler::Human)->and(otfFlow())->toBeNull();
});

it('opens cancel/edit with the verified order carried, without asking the number again', function () {
    $order = otfOrder();
    otfCard();
    $asked = count(array_filter(otfBodies(), fn ($b) => $b === TrackingFlowUpgrade::ASK_TEXT));

    otfTap('status', 'cancel_edit', 'عايزة ألغي/أعدل');

    $flow = otfFlow();
    expect($flow['key'])->toBe('cancel_edit')
        ->and($flow['step'])->toBe('request')
        ->and($flow['data']['order_id'])->toBe($order->id)
        ->and($flow['data']['order_number'])->toBe('#1047')
        ->and($flow['data']['order_verified'])->toBeTrue()
        ->and($flow['data'])->not->toHaveKey('order_eta')
        ->and($flow['data']['order_editable'])->toBe('yes')
        ->and(otfBot()->body)->toBe('أهلاً يا سارة 🌸 أوردر #1047 — تحبي تلغيه ولا تعدلي فيه؟')
        ->and(count(array_filter(otfBodies(), fn ($b) => $b === TrackingFlowUpgrade::ASK_TEXT)))->toBe($asked);

    otfTap('request', 'cancel', 'إلغاء', 'cancel_edit');
    expect(otfBot()->body)->toBe('ممكن تكتبيلي سبب الإلغاء؟ 🙏');
    otfTurn('طلبت مقاس غلط');

    $case = SupportCase::sole();
    expect($case->type)->toBe('cancel_edit')->and($case->order_id)->toBe($order->id)
        ->and($case->data['cancel_reason'])->toBe('طلبت مقاس غلط')
        ->and(otfBot()->body)->toBe('تمام ✅ سجلت طلب إلغاء أوردر #1047، والفريق هيأكد معاكي الإلغاء في أقرب وقت 🌸');
});

it('shows a delivered order without a window and opens return/exchange with the order carried', function () {
    $order = otfOrder([], ['shipment_status' => 'delivered', 'tracking_url' => 'https://track.example/1047', 'delivered_at' => CarbonImmutable::parse('2026-09-18 13:00', 'Africa/Cairo'), 'shopify_created_at' => now()->subDays(2)]);
    Shipment::factory()->for($order)->create(['status' => ShipmentStatus::Delivered]);
    otfCard();

    expect(otfBot()->body)->toBe("أهلاً يا سارة 🌸 أوردر #1047 (اتطلب يوم الخميس 17/9 — 3 قطع)\n📦 الحالة: اتسلم")
        ->and(otfButtons())->toBe(['تمام شكرًا', 'عايزة أرجع أو أبدل', 'كلم موظف']);

    // The open-order buttons are not answers for a delivered one.
    otfTap('status', 'late', 'الأوردر اتأخر');
    expect(otfFlow()['step'])->toBe('status')->and(SupportCase::count())->toBe(0);

    otfTap('status', 'return_exchange', 'عايزة أرجع أو أبدل');

    $flow = otfFlow();
    expect($flow['key'])->toBe('return_exchange')
        ->and($flow['step'])->toBe('kind')
        ->and($flow['data']['order_id'])->toBe($order->id)
        ->and(otfBot()->body)->toBe('أهلاً يا سارة 🌸 لقيت أوردر #1047 — تحبي ترجعي ولا تبدلي؟')
        ->and(otfBodies())->not->toContain(OrderStep::VERIFY_TEXT)
        ->and(implode("\n", otfBodies()))->toContain('14 يوم');

    otfTap('kind', 'return', 'إرجاع', 'return_exchange');
    expect(otfFlow()['step'])->toBe('return_items');
});

it('shows a cancelled order with the finished buttons', function () {
    otfOrder(['cancelled_at' => now()->subDay()]);
    otfCard();

    expect(otfBot()->body)->toContain('📦 الحالة: اتلغى')
        ->and(otfBot()->body)->not->toContain('🚚')
        ->and(otfButtons())->toBe(['تمام شكرًا', 'عايزة أرجع أو أبدل', 'كلم موظف']);
});

it('never carries an unverified order into another flow', function () {
    expect(OrderStep::carried(['order_id' => 5, 'order_number' => '#5']))->toBe([])
        ->and(OrderStep::carried(['order_id' => 5, 'order_number' => '#5', 'order_verified' => true, 'order_eta' => 'x', 'reason' => 'size']))
        ->toBe(['order_id' => 5, 'order_number' => '#5', 'order_verified' => true]);
});

// ---- delivery estimate ----------------------------------------------------------------------------

it('counts the delivery window in working days without Fridays, by the shipping governorate', function () {
    $estimate = app(DeliveryEstimate::class);
    $thursday = CarbonImmutable::parse('2026-09-17 23:30', 'Africa/Cairo');

    expect(DeliveryEstimate::text($estimate->window($thursday, true)))->toBe('الإثنين 21/9 لحد الأربعاء 23/9')
        ->and(DeliveryEstimate::text($estimate->window($thursday, false)))->toBe('الأربعاء 23/9 لحد السبت 26/9')
        ->and($estimate->isMainCity(new Order(['shipping_province_code' => 'GZ'])))->toBeTrue()
        ->and($estimate->isMainCity(new Order(['shipping_province_code' => 'DK', 'shipping_city' => 'القاهرة'])))->toBeFalse()
        ->and($estimate->isMainCity(new Order(['shipping_city' => 'الشيخ زايد'])))->toBeTrue()
        ->and($estimate->isMainCity(new Order(['shipping_city' => 'x', 'shipping_address' => 'شارع ٩ المعادي'])))->toBeTrue()
        ->and($estimate->isMainCity(new Order(['shipping_city' => 'طنطا'])))->toBeFalse()
        ->and($estimate->overdue($estimate->window($thursday, true), CarbonImmutable::parse('2026-09-23 23:00', 'Africa/Cairo')))->toBeFalse()
        ->and($estimate->overdue($estimate->window($thursday, true), CarbonImmutable::parse('2026-09-24 09:00', 'Africa/Cairo')))->toBeTrue()
        ->and(StatusStep::itemsCount(1))->toBe('قطعة واحدة')
        ->and(StatusStep::itemsCount(2))->toBe('قطعتين')
        ->and(StatusStep::itemsCount(3))->toBe('3 قطع')
        ->and(StatusStep::itemsCount(12))->toBe('12 قطعة')
        ->and(StatusStep::itemsCount(0))->toBeNull();
});

it('drops the card lines whose values are unknown', function () {
    $text = app(FlowPrompter::class)->renderText(StatusStep::CARD_TEXT, ['order_number' => '#9', 'order_date' => 'السبت 19/9', 'order_status' => 'اتلغى']);

    expect($text)->toBe("أهلاً 🌸 أوردر #9 (اتطلب يوم السبت 19/9)\n📦 الحالة: اتلغى");
});

// ---- definition, designer and publishing --------------------------------------------------------

it('seeds the owner flow for order_tracking, valid and without warnings', function () {
    $def = BotFlow::where('key', 'order_tracking')->firstOrFail()->definition;

    expect($def)->toBe(TrackingFlowUpgrade::definition())
        ->and(FlowDefinitions::all()['order_tracking']['definition'])->toBe(TrackingFlowUpgrade::definition())
        ->and(FlowDefinition::validate($def))->toBe([])
        ->and(FlowDefinition::validateReferences($def))->toBe([])
        ->and(FlowDefinition::warnings($def))->toBe([])
        ->and(FlowStepCatalog::all()['status']['options'])->toBe('choice')
        ->and(FlowStepCatalog::all()['status']['fields'])->toBe(['text', 'field'])
        ->and(FlowStepCatalog::all()['script']['fields'])->toContain('text')
        ->and(ClaudeFlowAnswerInterpreter::allowedValues($def['steps']['status']))->toBe(['thanks', 'late', 'cancel_edit', 'return_exchange', 'agent']);
});

it('validates status options, option actions and script texts for the designer', function () {
    $def = fn (array $steps) => ['start' => 'a', 'steps' => $steps];

    expect(FlowDefinition::validate($def(['a' => ['type' => 'status', 'next' => 'end']])))->toBe([])
        ->and(FlowDefinition::validate($def(['a' => ['type' => 'script', 'text' => 'شكرًا', 'next' => 'end']])))->toBe([])
        ->and(FlowDefinition::validate($def(['a' => ['type' => 'script', 'next' => 'end']])))->toBe(["step 'a' of type 'script' requires a 'script' key"])
        ->and(FlowDefinition::validate($def(['a' => ['type' => 'status', 'options' => []]])))->toBe(["step 'a' of type 'choice' must have between 1 and 13 options"])
        ->and(FlowDefinition::validate($def(['a' => ['type' => 'status', 'options' => [
            ['value' => 'x', 'title' => 'X', 'when' => 'later'],
            ['value' => 'y', 'title' => 'Y', 'action' => 'script:foo'],
            ['value' => 'z', 'title' => 'Z', 'action' => 'handover', 'next' => 'end'],
        ]]])))->toBe([
            "step 'a' option #0 'when' must be open or finished (status steps only)",
            "step 'a' option #1 'action' must be flow:<key>, menu:<key> or handover",
            "step 'a' option #2 has both a 'next' step and an 'action'",
        ])
        ->and(FlowDefinition::validate($def(['a' => ['type' => 'choice', 'field' => 'f', 'options' => [
            ['value' => 'x', 'title' => 'X', 'action' => 'flow:cancel_edit'],
            ['value' => 'y', 'title' => 'Y', 'when' => 'open'],
        ]]])))->toBe(["step 'a' option #1 'when' must be open or finished (status steps only)"])
        ->and(FlowDefinition::validateReferences($def(['a' => ['type' => 'status', 'options' => [
            ['value' => 'x', 'title' => 'X', 'action' => 'flow:nope'],
        ]]])))->toBe(["step 'a' option #0 references unknown flow 'nope'"]);
});

it('jumps to another flow from a choice option action too', function () {
    otfOrder();
    $flow = BotFlow::where('key', 'order_tracking')->firstOrFail();
    $def = TrackingFlowUpgrade::definition();
    $def['steps']['not_found']['options'][] = ['value' => 'complain', 'title' => 'شكوى', 'action' => 'flow:complaint'];
    $flow->update(['definition' => $def]);
    otfStart();
    otfTurn('999999');
    otfTurn('888888');

    otfTap('not_found', 'complain', 'شكوى');

    expect(otfFlow()['key'])->toBe('complaint')->and(otfFlow()['data'])->toBe([]);
});

function otfLegacyInstall(): BotFlow
{
    $flow = BotFlow::where('key', 'order_tracking')->firstOrFail();
    $flow->versions()->delete();
    $flow->update(['definition' => TrackingFlowUpgrade::legacyDefinition()]);
    $flow->versions()->create(['version' => 1, 'status' => 'published', 'definition' => TrackingFlowUpgrade::legacyDefinition(), 'published_at' => now()]);

    return $flow->fresh();
}

function otfMigrate(): void
{
    (require database_path('migrations/2026_09_19_400010_publish_owner_order_tracking_flow.php'))->up();
}

it('publishes the owner flow as a new version, archiving the old one and a draft, idempotently', function () {
    $flow = otfLegacyInstall();
    $mine = TrackingFlowUpgrade::legacyDefinition();
    $mine['steps']['order']['text'] = 'مسودتي';
    $draft = $flow->versions()->create(['version' => 2, 'status' => 'draft', 'definition' => $mine]);

    otfMigrate();

    $flow->refresh();
    $published = $flow->versions()->where('status', 'published')->sole();
    expect($flow->definition)->toBe(TrackingFlowUpgrade::definition())
        ->and($published->version)->toBe(3)
        ->and($published->note)->toBe(TrackingFlowUpgrade::NOTE)
        ->and($published->definition)->toBe(TrackingFlowUpgrade::definition())
        ->and($flow->versions()->where('status', 'archived')->count())->toBe(2)
        ->and($draft->fresh()->status)->toBe('archived')
        ->and($draft->fresh()->definition['steps']['order']['text'])->toBe('مسودتي')
        ->and($flow->draft()->exists())->toBeFalse();

    otfMigrate();
    expect($flow->versions()->count())->toBe(3)->and($flow->fresh()->definition)->toBe(TrackingFlowUpgrade::definition());
});

// ---- sandbox --------------------------------------------------------------------------------------

function otfSandbox(?array $state, array $input): array
{
    return app(FlowSandbox::class)->run(BotFlow::where('key', 'order_tracking')->firstOrFail(), 'published', $state, $input, User::factory()->create(['role' => UserRole::Supervisor]));
}

it('walks the tracking flow in the designer sandbox and saves nothing', function () {
    otfOrder(['placed_at' => CarbonImmutable::parse('2026-09-01 10:00', 'Africa/Cairo')]);
    $texts = fn (array $r) => implode("\n", array_column($r['messages'], 'text'));

    $start = otfSandbox(null, []);
    expect($texts($start))->toBe(TrackingFlowUpgrade::ASK_TEXT);

    $card = otfSandbox($start['state'], ['text' => '01001234567']);
    expect($card['current']['step_id'])->toBe('status')
        ->and($texts($card))->toStartWith('أهلاً يا سارة 🌸 أوردر #1047 (اتطلب يوم الثلاثاء 1/9 — 3 قطع)')
        ->and(array_column($card['messages'][0]['buttons'], 'title'))->toBe(['تمام شكرًا', 'الأوردر اتأخر', 'عايزة ألغي/أعدل', 'كلم موظف'])
        ->and($card['events'])->toBe([]);

    $late = otfSandbox($card['state'], ['payload' => 'step:order_tracking:status:late']);
    $case = collect($late['events'])->firstWhere('type', 'case');
    expect($case['label'])->toBe('هيتسجل حالة: '.SupportCase::TYPE_LABELS['delivery_followup'])
        ->and($texts($late))->toBe('سجلت طلب متابعة للأوردر #1047 🌸 الفريق هيتابع مع شركة الشحن ويرد عليكي في أقرب وقت')
        ->and($late['current'])->toBeNull();

    $edit = otfSandbox($card['state'], ['payload' => 'step:order_tracking:status:cancel_edit']);
    expect($edit['current'])->toBe(['flow_key' => 'cancel_edit', 'step_id' => 'request'])
        ->and(collect($edit['events'])->firstWhere('type', 'flow_start'))->not->toBeNull()
        ->and($texts($edit))->toBe('أهلاً يا سارة 🌸 أوردر #1047 — تحبي تلغيه ولا تعدلي فيه؟');

    expect(SupportCase::count())->toBe(0)->and(Conversation::count())->toBe(0);
});
