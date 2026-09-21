<?php

use App\Bot\Flow\ReplyScheduler;
use App\Bot\Flows\EntityExtractor;
use App\Bot\Flows\FakeFlowAnswerInterpreter;
use App\Bot\Flows\FlowAnswerInterpreter;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowPrompter;
use App\Bot\Flows\FlowResult;
use App\Bot\Flows\FlowState;
use App\Channels\Data\InboundMessageData;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\ShipmentStatus;
use App\Inbox\InboxIngestor;
use App\Models\BotSetting;
use App\Models\Branch;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Order;
use App\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    useOrderAwareReturnFlow();
    // The 2026-09-17 cancel_edit (order step without the ownership check) hosts the order-step tests.
    useLegacyOwnerFlows('cancel_edit');
    Event::fake();
    Http::preventStrayRequests();
    config(['crm.drivers.ai' => 'fake']);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => false, 'working_hours' => null]);
    app()->bind(FlowAnswerInterpreter::class, FakeFlowAnswerInterpreter::class);
});

function stepsSay(string $text, ?string $payload = null): Conversation
{
    static $n = 0;
    $n++;
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-1', 'Mona', 'st'.$n.'-'.uniqid(), $text, CarbonImmutable::now(), payload: $payload,
    ));

    return Conversation::firstOrFail();
}

function stepsTurn(string $text, ?string $payload = null): FlowResult
{
    $c = stepsSay($text, $payload);

    return app(FlowEngine::class)->handle($c, app(ReplyScheduler::class)->burst($c));
}

function stepsRun(Conversation $c): FlowResult
{
    return app(FlowEngine::class)->handle($c->fresh(), app(ReplyScheduler::class)->burst($c->fresh()));
}

function stepsLastBot(): Message
{
    return Message::where('sender_type', SenderType::Bot->value)->latest('id')->firstOrFail();
}

function stepsFlow(): ?array
{
    return FlowState::flow(Conversation::firstOrFail());
}

/** Puts the conversation straight on a step of a seeded flow. */
function stepsAt(string $flow, string $step, array $data = []): Conversation
{
    $c = stepsSay('اهلا');
    FlowState::put($c, ['key' => $flow, 'step' => $step, 'data' => $data, 'retries' => 0, 'started_at' => now()->toIso8601String()]);

    return $c->fresh();
}

it('extracts order refs, phones and emails', function () {
    expect(EntityExtractor::orderRef('رقم الاوردر #5566'))->toBe('5566')
        ->and(EntityExtractor::orderRef('موبايلي 01012345678'))->toBeNull()
        ->and(EntityExtractor::phone('موبايلي ٠١٠١٢٣٤٥٦٧٨'))->toBe('01012345678')
        ->and(EntityExtractor::email('mail: Mona.A@test.com'))->toBe('Mona.A@test.com')
        ->and(EntityExtractor::email('مفيش'))->toBeNull();
});

// cancel_edit's order step has no ownership check (the return flow's is covered in OrderAwareReturnsTest).
it('finds the order by number and moves to the next step', function () {
    $o = Order::factory()->create(['order_number' => '5566']);
    Shipment::factory()->for($o)->create(['status' => ShipmentStatus::InTransit]);

    $c = stepsSay('اهلا');
    app(FlowEngine::class)->start($c, 'cancel_edit');
    expect(stepsFlow()['step'])->toBe('order');

    $r = stepsTurn('رقم الأوردر 5566');
    $flow = stepsFlow();
    expect($r->handled)->toBeTrue()
        ->and($flow['step'])->toBe('request')
        ->and($flow['data']['order_number'])->toBe('#5566')
        ->and($flow['data']['order_id'])->toBe($o->id)
        ->and($flow['data']['order_placed_at'])->toBeString()
        ->and($flow['data']['order_status_line'])->toContain('#5566')
        ->and($flow['data']['order_status_key'])->toBe('shipped')
        ->and(stepsLastBot()->body)->toBe('حضرتك عايزة تلغي الأوردر ولا تعدل فيه؟');
});

it('asks again when the order is not found, then keeps what she typed', function () {
    $c = stepsSay('اهلا');
    app(FlowEngine::class)->start($c, 'cancel_edit');

    stepsTurn('123456');
    expect(stepsFlow()['step'])->toBe('order')
        ->and(stepsLastBot()->body)->toBe('مش لاقية أوردر بالبيانات دي 🌸 ممكن تتأكدي من الرقم؟');

    stepsTurn('مش فاكرة الرقم');
    $flow = stepsFlow();
    expect($flow['step'])->toBe('request')
        ->and($flow['data']['order_ref_text'])->toBe('مش فاكرة الرقم')
        ->and($flow['data'])->not->toHaveKey('order_number')
        ->and($flow['retries'])->toBe(0)
        ->and(app(FlowPrompter::class)->summaryLines($flow['data']))->toBe(['• بيانات الأوردر: مش فاكرة الرقم']);
});

it('lists several open orders found by phone and waits', function () {
    Order::factory()->create(['shipping_phone' => '+201001234567', 'order_number' => '1111']);
    Order::factory()->create(['shipping_phone' => '+201001234567', 'order_number' => '2222']);
    $c = stepsSay('اهلا');
    app(FlowEngine::class)->start($c, 'order_tracking');

    stepsTurn('01001234567');
    expect(stepsFlow()['step'])->toBe('order')
        ->and(stepsFlow()['retries'])->toBe(1)
        ->and(stepsLastBot()->body)->toStartWith('لقيت أكتر من أوردر: #')
        ->and(stepsLastBot()->body)->toEndWith('تحبي أتابع أنهي واحد؟');

    // Typing one of them (her mobile already proved they are hers) opens its status card.
    stepsTurn('#2222');
    expect(stepsFlow()['step'])->toBe('status')
        ->and(stepsFlow()['data']['order_number'])->toBe('#2222')
        ->and(stepsLastBot()->body)->toContain('#2222');
});

it('stores the photo from a legacy image attachment and continues', function () {
    $c = stepsAt('return_exchange', 'product_photo', ['reason' => 'size']);
    Message::factory()->for($c, 'conversation')->create(['direction' => 'in', 'sender_type' => 'customer', 'body' => null, 'attachments' => [['type' => 'image']]]);

    $r = stepsRun($c);
    $flow = stepsFlow();
    expect($r->handled)->toBeTrue()
        ->and($flow['data']['product_photo'])->toBe(['legacy'])
        ->and($flow['step'])->toBe('summary');
});

it('stores media attachment ids and asks for the defect photo when defective', function () {
    $c = stepsAt('return_exchange', 'product_photo', ['reason' => 'defective']);
    $m = Message::factory()->for($c, 'conversation')->create(['direction' => 'in', 'sender_type' => 'customer', 'body' => null]);
    $a = MessageAttachment::factory()->create(['message_id' => $m->id]);

    stepsRun($c);
    $flow = stepsFlow();
    expect($flow['data']['product_photo'])->toBe([$a->id])
        ->and($flow['step'])->toBe('defect_photo')
        ->and(stepsLastBot()->body)->toBe('وممكن صورة توضح العيب اللي في المنتج؟ 📸');
});

it('re-asks once for a photo then continues with the missing flag', function () {
    stepsAt('return_exchange', 'product_photo', ['reason' => 'size']);

    stepsTurn('مش معايا صورة دلوقتي');
    expect(stepsFlow()['step'])->toBe('product_photo')
        ->and(stepsLastBot()->body)->toBe('ممكن صورة واضحة للمنتج؟ 📸');

    stepsTurn('مش هقدر');
    $flow = stepsFlow();
    expect($flow['step'])->toBe('summary')
        ->and($flow['data']['product_photo_missing'])->toBeTrue()
        ->and($flow['data'])->not->toHaveKey('product_photo');
});

it('lists the branches of an area typed in the branches flow', function () {
    $c = stepsSay('اهلا');
    app(FlowEngine::class)->start($c, 'branches');
    $prompt = stepsLastBot();
    expect($prompt->buttons)->toHaveCount(13)
        ->and($prompt->buttons[0]['payload'])->toStartWith('step:branches:list:area:')
        ->and($prompt->buttons[12]['payload'])->toBe('menu:main_menu');

    stepsTurn('مدينة نصر');
    $cards = Message::whereNotNull('cards')->latest('id')->firstOrFail();
    expect(substr_count($cards->body, '📍'))->toBe(5)
        ->and($cards->cards['cards'])->toHaveCount(5)
        ->and(stepsLastBot()->body)->toBe('تحبي حاجة تانية؟')
        ->and(array_column(stepsLastBot()->buttons, 'title'))->toBe(['فرع في منطقة تانية', 'القائمة الرئيسية'])
        ->and(stepsFlow()['step'])->toBe('more');
});

it('lists the branches of a tapped area', function () {
    $c = stepsSay('اهلا');
    app(FlowEngine::class)->start($c, 'branches');

    stepsTurn('الإسكندرية', 'step:branches:list:area:alexandria');
    expect(Message::whereNotNull('cards')->latest('id')->firstOrFail()->cards['cards'])->toHaveCount(4)
        ->and(stepsFlow()['step'])->toBe('more');
});

it('re-asks the area when the branches text is not a known area', function () {
    $c = stepsSay('اهلا');
    app(FlowEngine::class)->start($c, 'branches');

    stepsTurn('مش عارفة');
    expect(stepsFlow()['step'])->toBe('list')
        ->and(stepsFlow()['retries'])->toBe(1)
        ->and(stepsLastBot()->buttons)->toHaveCount(13);
});

it('offers the branch buttons of a typed area in the complaint and saves the tapped branch', function () {
    stepsAt('complaint', 'branch', ['complaint_type' => 'branch']);

    stepsTurn('التجمع');
    $buttons = stepsLastBot()->buttons;
    expect($buttons)->toHaveCount(4)
        ->and($buttons[3]['payload'])->toBe('menu:main_menu')
        ->and($buttons[0]['payload'])->toStartWith('step:complaint:branch:branch:')
        ->and(stepsFlow()['step'])->toBe('branch');

    foreach ($buttons as $b) {
        expect(mb_strlen($b['title']))->toBeLessThanOrEqual(20);
    }

    $id = (int) substr($buttons[1]['payload'], strlen('step:complaint:branch:branch:'));
    stepsTurn($buttons[1]['title'], $buttons[1]['payload']);
    $flow = stepsFlow();
    expect($flow['step'])->toBe('visit_date')
        ->and($flow['data']['branch_id'])->toBe($id)
        ->and($flow['data']['branch_name'])->toBe(Branch::find($id)->name);

    // A stale area tap from the finished branch step re-asks the visit date and saves nothing.
    stepsTurn('التجمع الخامس', 'step:complaint:branch:area:fifth_settlement');
    expect(stepsFlow()['step'])->toBe('visit_date')
        ->and(stepsFlow()['data'])->not->toHaveKey('visit_date')
        ->and(stepsLastBot()->body)->toBe('إحنا خلصنا الخطوة دي فعلًا 🌸
كانت الزيارة إمتى تقريبًا؟');
});

it('opens the area buttons on entering the branch step and branch buttons on an area tap', function () {
    $c = stepsSay('اهلا');
    app(FlowEngine::class)->start($c, 'complaint');
    stepsTurn('فرع', 'step:complaint:type:branch');
    expect(stepsLastBot()->body)->toBe('اكتبي اسم الفرع، أو اختاري المنطقة من هنا 👇')
        ->and(stepsLastBot()->buttons)->toHaveCount(13);

    stepsTurn('الإسكندرية', 'step:complaint:branch:area:alexandria');
    expect(stepsLastBot()->buttons)->toHaveCount(5)
        ->and(stepsFlow()['step'])->toBe('branch');
});

it('stores the failed attempt flag with the order', function () {
    $o = Order::factory()->create(['order_number' => '7801']);
    Shipment::factory()->for($o)->create(['status' => ShipmentStatus::FailedAttempt]);
    $c = stepsSay('اهلا');
    app(FlowEngine::class)->start($c, 'cancel_edit');

    stepsTurn('7801');
    expect(stepsFlow()['data']['order_failed_attempt'])->toBeTrue();
});
