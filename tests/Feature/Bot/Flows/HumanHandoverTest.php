<?php

use App\Bot\Flow\FakeTurnUnderstanding;
use App\Bot\Flow\TurnUnderstanding;
use App\Bot\Flows\FakeFlowAnswerInterpreter;
use App\Bot\Flows\FlowAnswerInterpreter;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowState;
use App\Bot\Flows\HumanHandover;
use App\Bot\Flows\Jobs\HandoverTopicTimeout;
use App\Bot\WorkingHours;
use App\Channels\Data\InboundMessageData;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Http\Resources\ConversationResource;
use App\Inbox\ConversationActions;
use App\Inbox\InboxIngestor;
use App\Models\BotKnowledgeEntry;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

// «كلم موظف» (the owner's flow 7, 2026-09-19): the topic question, the topic note and the
// working-hours aware reply. "Now" is Saturday 19 September 2026, noon in Cairo.

const HH_IN_HOURS = 'تمام ✅ هيتم تحويلك لموظف خدمة العملاء خلال دقايق 🌸';
const HH_NO_HOURS = 'تمام ✅ هيتم تحويلك لموظف خدمة العملاء، هيرد عليكي في أقرب وقت 🌸';
const HH_ASK = 'أكيد 🌸 ممكن تقوليلي باختصار محتاجة إيه؟ عشان أوصّلك للشخص المناسب على طول';

beforeEach(function () {
    Event::fake();
    Http::preventStrayRequests();
    config(['crm.drivers.ai' => 'fake']);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => true, 'ai_enabled' => true, 'working_hours' => null, 'min_confidence' => 0.6]);
    app()->bind(TurnUnderstanding::class, FakeTurnUnderstanding::class);
    app()->bind(FlowAnswerInterpreter::class, FakeFlowAnswerInterpreter::class);
    $this->travelTo(CarbonImmutable::parse('2026-09-19 12:00', 'Africa/Cairo'));
});

/** One customer message through the whole bot pipeline. */
function hhSay(string $text, ?string $payload = null): Conversation
{
    static $n = 0;
    $n++;
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-HH', 'Mona', 'hh'.$n.'-'.uniqid(), $text, CarbonImmutable::now(), payload: $payload,
    ));

    return Conversation::firstOrFail()->fresh();
}

function hhBot(): Message
{
    return Message::where('sender_type', SenderType::Bot->value)->latest('id')->firstOrFail();
}

/** @return list<string> */
function hhBodies(): array
{
    return Message::where('sender_type', SenderType::Bot->value)->orderBy('id')->pluck('body')->map(fn ($b) => (string) $b)->all();
}

/** The main menu is on screen and she taps «كلم موظف». */
function hhAsk(): Conversation
{
    hhSay('هاي');

    return hhSay('كلم موظف', 'handover');
}

it('asks what she needs first, with a skip button, and keeps the conversation with the bot', function () {
    Bus::fake([HandoverTopicTimeout::class]);
    $c = hhAsk();

    expect(hhBot()->body)->toBe(HH_ASK)
        ->and(hhBot()->buttons)->toBe([['title' => 'حوّليني على طول', 'payload' => 'handover:now']])
        ->and($c->handler)->toBe(Handler::Bot)
        ->and(HumanHandover::pending($c))->toBeTrue()
        ->and(FlowState::flow($c))->toBeNull();

    Bus::assertDispatched(HandoverTopicTimeout::class, fn ($job) => $job->conversationId === $c->id);
});

it('saves her answer as the topic note and the handover reason, then the reply', function () {
    hhAsk();
    $c = hhSay('عايزة أغير عنوان الأوردر اللي لسه طالباه');

    expect($c->handler)->toBe(Handler::Human)
        ->and($c->handover_topic)->toBe('عايزة أغير عنوان الأوردر اللي لسه طالباه')
        ->and($c->handover_category)->toBe('human_request')
        ->and(HumanHandover::pending($c))->toBeFalse()
        ->and(ConversationNote::where('conversation_id', $c->id)->where('body', 'موضوع التحويل: عايزة أغير عنوان الأوردر اللي لسه طالباه')->exists())->toBeTrue()
        ->and(hhBot()->body)->toBe(HH_NO_HOURS)
        ->and((new ConversationResource($c->load('customer')))->resolve(request())['handover_topic'])->toBe('عايزة أغير عنوان الأوردر اللي لسه طالباه');
});

it('takes a photo as the topic', function () {
    $c = hhAsk();
    $m = Message::factory()->for($c, 'conversation')->create(['direction' => 'in', 'sender_type' => 'customer', 'body' => null]);
    MessageAttachment::factory()->create(['message_id' => $m->id]);
    app(FlowEngine::class)->handle($c->fresh(), collect([$m]));

    expect($c->fresh()->handler)->toBe(Handler::Human)->and($c->fresh()->handover_topic)->toBe(HumanHandover::PHOTO_TOPIC);
});

it('skips the question on «حوّليني على طول» or «مش مهم»', function (string $text, ?string $payload) {
    hhAsk();
    $c = hhSay($text, $payload);

    expect($c->handler)->toBe(Handler::Human)
        ->and($c->handover_topic)->toBeNull()
        ->and(ConversationNote::where('body', 'like', 'موضوع التحويل%')->exists())->toBeFalse()
        ->and(hhBot()->body)->toBe(HH_NO_HOURS);
})->with([
    'the button' => ['حوّليني على طول', 'handover:now'],
    'typed' => ['مش مهم', null],
]);

it('hands over anyway when she does not answer in time, only for the question that is still waiting', function () {
    Bus::fake([HandoverTopicTimeout::class]);
    $c = hhAsk();
    $askedAt = $c->bot_state[HumanHandover::STATE_KEY]['asked_at'];

    (new HandoverTopicTimeout($c->id, $askedAt))->handle(app(HumanHandover::class));
    expect($c->fresh()->handler)->toBe(Handler::Bot); // not due yet

    $this->travel(HumanHandover::TOPIC_WAIT_SECONDS + 1)->seconds();
    (new HandoverTopicTimeout($c->id, 'another-question'))->handle(app(HumanHandover::class));
    expect($c->fresh()->handler)->toBe(Handler::Bot);

    (new HandoverTopicTimeout($c->id, $askedAt))->handle(app(HumanHandover::class));
    expect($c->fresh()->handler)->toBe(Handler::Human)
        ->and($c->fresh()->handover_topic)->toBeNull()
        ->and(hhBot()->body)->toBe(HH_NO_HOURS);
});

it('replies by the working hours: inside, after (next opening), and none set', function (?array $hours, string $at, string $expected) {
    BotSetting::current()->update(['working_hours' => $hours]);

    expect(app(HumanHandover::class)->hoursReply(CarbonImmutable::parse($at, 'Africa/Cairo')))->toBe($expected);
})->with([
    'inside hours' => [['days' => [0, 1, 2, 3, 4, 5, 6], 'from' => '10:00', 'to' => '22:00'], '2026-09-19 12:00', HH_IN_HOURS],
    'after closing: tomorrow' => [['days' => [0, 1, 2, 3, 4, 5, 6], 'from' => '10:00', 'to' => '22:00'], '2026-09-19 23:30', 'تمام ✅ سجلت طلبك، وهيتم تحويلك لموظف خدمة العملاء أول ما نفتح بكرة الساعة 10 الصبح 🌸'],
    'before opening: today' => [['days' => [0, 1, 2, 3, 4, 5, 6], 'from' => '10:30', 'to' => '22:00'], '2026-09-19 08:00', 'تمام ✅ سجلت طلبك، وهيتم تحويلك لموظف خدمة العملاء أول ما نفتح النهارده الساعة 10:30 الصبح 🌸'],
    'friday off: saturday' => [['days' => [0, 1, 2, 3, 4, 6], 'from' => '10:00', 'to' => '22:00'], '2026-09-17 23:00', 'تمام ✅ سجلت طلبك، وهيتم تحويلك لموظف خدمة العملاء أول ما نفتح يوم السبت الساعة 10 الصبح 🌸'],
    'no hours set' => [null, '2026-09-19 03:00', HH_NO_HOURS],
]);

it('names the opening hour the Egyptian way', function () {
    $t = fn (string $time) => WorkingHours::clock(CarbonImmutable::parse("2026-09-19 {$time}", 'Africa/Cairo'));

    expect($t('10:00'))->toBe('10 الصبح')->and($t('13:00'))->toBe('1 الضهر')->and($t('16:30'))->toBe('4:30 العصر')->and($t('19:00'))->toBe('7 بالليل');
});

it('uses the owner\'s edited scripts and skips a question she turned off', function () {
    BotKnowledgeEntry::where('key', 'script.handover_no_hours')->update(['body' => 'حولناكي للفريق 🌸']);
    BotKnowledgeEntry::where('key', 'script.handover_ask_topic')->update(['is_active' => false]);

    $c = hhAsk();

    expect($c->handler)->toBe(Handler::Human)
        ->and(hhBodies())->not->toContain(HH_ASK)
        ->and(hhBot()->body)->toBe('حولناكي للفريق 🌸');
});

it('asks the topic when she asks for a person in words, and takes a request that says why as its own topic', function () {
    $c = hhSay('عايز اكلم حد');
    expect(hhBot()->body)->toBe(HH_ASK)->and($c->handler)->toBe(Handler::Bot);

    Conversation::query()->update(['bot_state' => null]);
    $c = hhSay('عايزة اكلم حد عشان الأوردر اتأخر جدا');
    expect($c->handler)->toBe(Handler::Human)
        ->and($c->handover_topic)->toBe('عايزة اكلم حد عشان الأوردر اتأخر جدا');
});

it('catches the human_request words («خدمة العملاء») before the agent', function () {
    $c = hhSay('ممكن خدمة العملاء');

    expect(hhBot()->body)->toBe(HH_ASK)->and(HumanHandover::pending($c))->toBeTrue();
});

it('skips the question when the bot hands over itself (a flow handover step)', function () {
    hhSay('هاي');
    $c = Conversation::firstOrFail();
    app(FlowEngine::class)->runPayload($c, 'flow:order_tracking');
    hhSay('5555');
    hhSay('9999');
    $c = hhSay('كلم موظف', 'step:order_tracking:not_found:agent');

    expect($c->handler)->toBe(Handler::Human)
        ->and(hhBodies())->not->toContain(HH_ASK)
        ->and(hhBot()->body)->toBe(HH_NO_HOURS);
});

it('hands over straight away from inside a flow when she asks for a person there', function () {
    hhSay('هاي');
    hhSay('شكوى', 'flow:complaint');
    $c = hhSay('عايز اكلم حد');

    expect($c->handler)->toBe(Handler::Human)->and(hhBodies())->not->toContain(HH_ASK)->and(hhBot()->body)->toBe(HH_NO_HOURS);
});

it('forgets the topic when the conversation is resolved', function () {
    hhAsk();
    $c = hhSay('عايزة أسأل عن أوردر');
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    app(ConversationActions::class)->resolve($c, $admin);

    expect($c->fresh()->handover_topic)->toBeNull();
});
