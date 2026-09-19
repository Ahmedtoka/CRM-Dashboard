<?php

use App\Bot\Flow\ReplyScheduler;
use App\Bot\Flows\FakeFlowAnswerInterpreter;
use App\Bot\Flows\FlowAnswerInterpreter;
use App\Bot\Flows\FlowDefinition;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowResult;
use App\Bot\Flows\FlowState;
use App\Bot\Flows\OwnerFlowsUpgrade;
use App\Bot\Flows\Sandbox\FlowSandbox;
use App\Bot\Flows\Steps\BranchStep;
use App\Bot\Flows\Steps\ContactStep;
use App\Channels\Data\InboundMessageData;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Inbox\InboxIngestor;
use App\Models\BotFlow;
use App\Models\BotSetting;
use App\Models\Branch;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Order;
use App\Models\SupportCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

// The owner's complaint flow of 2026-09-19 (OwnerFlowsUpgrade::complaintDefinition()), end to end.

beforeEach(function () {
    Event::fake();
    Http::preventStrayRequests();
    config(['crm.drivers.ai' => 'fake']);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => false, 'working_hours' => null]);
    app()->bind(FlowAnswerInterpreter::class, FakeFlowAnswerInterpreter::class);
    $this->travelTo(CarbonImmutable::parse('2026-09-19 12:00', 'Africa/Cairo'));
});

function ocpSay(string $text, ?string $payload = null): Conversation
{
    static $n = 0;
    $n++;
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-OCP', 'Mona', 'ocp'.$n.'-'.uniqid(), $text, CarbonImmutable::now(), payload: $payload,
    ));

    return Conversation::firstOrFail();
}

function ocpTurn(string $text, ?string $payload = null): FlowResult
{
    $c = ocpSay($text, $payload);

    return app(FlowEngine::class)->handle($c, app(ReplyScheduler::class)->burst($c));
}

function ocpTap(string $step, string $value, string $title = 'x'): FlowResult
{
    return ocpTurn($title, "step:complaint:{$step}:{$value}");
}

function ocpFlow(): ?array
{
    return FlowState::flow(Conversation::firstOrFail());
}

function ocpBot(): Message
{
    return Message::where('sender_type', SenderType::Bot->value)->latest('id')->firstOrFail();
}

/** @return list<string> */
function ocpButtons(): array
{
    return array_column((array) ocpBot()->buttons, 'title');
}

function ocpStart(): void
{
    $c = ocpSay('اهلا');
    app(FlowEngine::class)->runPayload($c, 'flow:complaint');
}

function ocpPhoto(string $caption = ''): MessageAttachment
{
    $c = Conversation::firstOrFail();
    $m = Message::factory()->for($c, 'conversation')->create(['direction' => 'in', 'sender_type' => 'customer', 'body' => $caption !== '' ? $caption : null]);
    $a = MessageAttachment::factory()->create(['message_id' => $m->id]);
    app(FlowEngine::class)->handle($c->fresh(), app(ReplyScheduler::class)->burst($c->fresh()));

    return $a;
}

it('asks what the complaint is about with the five buttons', function () {
    ocpStart();

    expect(ocpBot()->body)->toBe('آسفين جدًا لده 🙏 الشكوى بخصوص إيه؟')
        ->and(ocpButtons())->toBe(['فرع', 'شحن وتوصيل', 'منتج', 'خدمة العملاء', 'حاجة تانية', 'القائمة الرئيسية']);
});

it('takes a typed branch name directly, then the visit date in her own words', function () {
    $branch = Branch::where('name', 'El Marghany')->firstOrFail();
    ocpStart();
    ocpTap('type', 'branch', 'فرع');

    expect(ocpBot()->body)->toBe('اكتبي اسم الفرع، أو اختاري المنطقة من هنا 👇')
        ->and(ocpBot()->buttons[0]['payload'])->toStartWith('step:complaint:branch:area:');

    ocpTurn('فرع المرغني');

    expect(ocpFlow()['step'])->toBe('visit_date')
        ->and(ocpFlow()['data']['branch_id'])->toBe($branch->id)
        ->and(ocpFlow()['data']['branch_name'])->toBe('El Marghany')
        ->and(ocpBot()->body)->toBe('كانت الزيارة إمتى تقريبًا؟')
        ->and(ocpButtons())->toBe(['النهارده', 'امبارح', 'من كام يوم', 'القائمة الرئيسية']);

    ocpTurn('الخميس اللي فات بالليل');
    expect(ocpFlow()['step'])->toBe('contact')
        ->and(ocpFlow()['data']['visit_date'])->toBe('الخميس اللي فات بالليل');
});

it('lists the branches that share a typed name, and an area choice then its branches', function () {
    ocpStart();
    ocpTap('type', 'branch', 'فرع');
    ocpTurn('Abbas El Akkad');

    // Two "Abbas El Akkad" stores: she picks one.
    expect(ocpBot()->body)->toBe(BranchStep::CHOOSE_BRANCH_TEXT)
        ->and(ocpButtons())->toBe(['Abbas El Akkad', 'Abbas El Akkad', 'القائمة الرئيسية']);

    ocpTurn('الإسكندرية', 'step:complaint:branch:area:alexandria');
    $buttons = ocpBot()->buttons;
    expect(ocpBot()->body)->toBe(BranchStep::CHOOSE_BRANCH_TEXT)->and($buttons)->toHaveCount(5);

    ocpTurn($buttons[0]['title'], $buttons[0]['payload']);
    ocpTap('visit_date', 'yesterday', 'امبارح');

    expect(ocpFlow()['data']['visit_date_title'])->toBe('امبارح')
        ->and(ocpFlow()['step'])->toBe('contact');
});

it('confirms a known name and mobile with the number masked, then records the complaint with photos', function () {
    Conversation::query()->delete();
    ocpStart();
    Conversation::first()->customer->update(['name' => 'سارة محمد', 'phone' => '+201061236611']);
    ocpTap('type', 'service', 'خدمة العملاء');

    expect(ocpBot()->body)->toBe('هنتواصل مع حضرتك باسم «سارة محمد» على رقم 0106•••6611 — تمام كده؟')
        ->and(ocpButtons())->toBe(['أيوه تمام', 'رقم تاني']);

    ocpTap('contact', 'yes', 'أيوه تمام');
    expect(ocpBot()->body)->toBe(OwnerFlowsUpgrade::DESCRIPTION_TEXT);

    $photo = ocpPhoto('محدش رد عليا يومين');

    $case = SupportCase::sole();
    expect($case->type)->toBe('complaint')
        ->and($case->data['name'])->toBe('سارة محمد')
        ->and($case->data['phone'])->toBe('01061236611')
        ->and($case->data['description'])->toBe('محدش رد عليا يومين')
        ->and($case->photo_attachment_ids)->toBe([$photo->id])
        ->and($case->summary)->toContain('سارة محمد · 01061236611')->toContain('صور من العميلة ✅')
        ->and(ocpBot()->body)->toBe("تمام ✅ سجلت الشكوى رقم #{$case->id}، والفريق هيتواصل معاكي في أقرب وقت 🌸")
        ->and(ocpFlow())->toBeNull();
});

it('asks only for the mobile after «رقم تاني»', function () {
    ocpStart();
    Conversation::first()->customer->update(['name' => 'سارة', 'phone' => '01061236611']);
    ocpTap('type', 'other', 'حاجة تانية');
    ocpTap('contact', 'other', 'رقم تاني');

    expect(ocpBot()->body)->toBe(ContactStep::ASK_PHONE_TEXT);

    ocpTurn('01234567890');
    expect(ocpFlow()['data'])->toMatchArray(['name' => 'سارة', 'phone' => '01234567890'])
        ->and(ocpFlow()['step'])->toBe('description');
});

it('reads the name and the mobile (Arabic digits too) from one message when she is unknown', function () {
    ocpStart();
    Conversation::first()->customer->update(['phone' => null]);
    ocpTap('type', 'service', 'خدمة العملاء');

    expect(ocpBot()->body)->toBe(ContactStep::ASK_BOTH_TEXT);

    ocpTurn('انا سارة احمد ورقمي ٠١٠١٢٣٤٥٦٧٨');
    expect(ocpFlow()['data'])->toMatchArray(['name' => 'سارة احمد', 'phone' => '01012345678'])
        ->and(ocpFlow()['step'])->toBe('description');
});

it('asks only for the missing one of the name and the mobile', function (string $first, string $asked, string $second, array $expected) {
    ocpStart();
    Conversation::first()->customer->update(['phone' => null]);
    ocpTap('type', 'service', 'خدمة العملاء');

    ocpTurn($first);
    expect(ocpBot()->body)->toBe($asked)->and(ocpFlow()['step'])->toBe('contact');

    ocpTurn($second);
    expect(ocpFlow()['data'])->toMatchArray($expected)->and(ocpFlow()['step'])->toBe('description');
})->with([
    'the mobile first' => ['01012345678', ContactStep::ASK_NAME_TEXT, 'منى', ['name' => 'منى', 'phone' => '01012345678']],
    'the name first' => ['منى علي', ContactStep::ASK_PHONE_TEXT, '+20 101 234 5678', ['name' => 'منى علي', 'phone' => '01012345678']],
]);

it('skips the contact question with a verified order: the name and mobile come from it', function () {
    $order = Order::factory()->create([
        'order_number' => '1047', 'shopify_order_name' => '#1047', 'shipping_name' => 'سارة أحمد', 'shipping_phone' => '+201001234567',
    ]);
    ocpStart();
    ocpTap('type', 'delivery', 'شحن وتوصيل');

    expect(ocpBot()->body)->toBe(OwnerFlowsUpgrade::ASK_ORDER_TEXT);

    ocpTurn('01001234567');
    expect(ocpFlow()['step'])->toBe('description')
        ->and(ocpBot()->body)->toBe(OwnerFlowsUpgrade::DESCRIPTION_TEXT)
        ->and(ocpFlow()['data'])->toMatchArray(['name' => 'سارة أحمد', 'phone' => '01001234567', 'contact_source' => 'order']);

    ocpTurn('المندوب اتأخر 3 أيام');
    $case = SupportCase::sole();
    expect($case->order_id)->toBe($order->id)->and($case->priority)->toBe('high');
});

it('masks a mobile as 0106•••6611', function () {
    expect(ContactStep::mask('01061236611'))->toBe('0106•••6611');
});

it('validates and walks the published complaint in the designer sandbox', function () {
    $flow = BotFlow::where('key', 'complaint')->firstOrFail();
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $sandbox = app(FlowSandbox::class);

    expect(FlowDefinition::validate($flow->definition))->toBe([])->and(FlowDefinition::warnings($flow->definition))->toBe([]);

    $r = $sandbox->run($flow, 'published', null, [], $user);
    foreach ([['payload' => 'step:complaint:type:branch'], ['text' => 'الحجاز'], ['payload' => 'step:complaint:visit_date:today'], ['text' => 'منى 01012345678'], ['text' => 'البياعة كانت مش لطيفة']] as $input) {
        $r = $sandbox->run($flow, 'published', $r['state'], $input, $user);
    }

    $case = collect($r['events'])->firstWhere('type', 'case');
    expect($case['data'])->toMatchArray(['branch_name' => 'El Hegaz', 'visit_date' => 'today', 'name' => 'منى', 'phone' => '01012345678'])
        ->and(end($r['messages'])['text'])->toStartWith('تمام ✅ سجلت الشكوى رقم #')->not->toContain('#0')
        ->and(SupportCase::count())->toBe(0);
});
