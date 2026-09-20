<?php

use App\Bot\Flow\FakeTurnUnderstanding;
use App\Bot\Flow\ReplyScheduler;
use App\Bot\Flow\TurnRunner;
use App\Bot\Flow\TurnUnderstanding;
use App\Bot\Flows\FakeFlowAnswerInterpreter;
use App\Bot\Flows\FlowAnswerInterpreter;
use App\Bot\Flows\FlowEngine;
use App\Channels\Data\InboundMessageData;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Inbox\InboxIngestor;
use App\Models\BotFlow;
use App\Models\BotKnowledgeEntry;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

// Fix 1 (2026-09-21): every handover ends with the working-hours aware transfer sentence,
// on the agent (AI/rules) path as well as in the guided flows. "Now" is Saturday
// 19 September 2026, noon in Cairo.

const HT_IN_HOURS = 'تمام ✅ هيتم تحويلك لموظف خدمة العملاء خلال دقايق 🌸';

const HT_NO_HOURS = 'تمام ✅ هيتم تحويلك لموظف خدمة العملاء، هيرد عليكي في أقرب وقت 🌸';

const HT_AFTER_HOURS = 'تمام ✅ سجلت طلبك، وهيتم تحويلك لموظف خدمة العملاء أول ما نفتح بكرة الساعة 10 الصبح 🌸';

const HT_OLD_ACK = 'تمام يا فندم، هراجع طلب حضرتك مع الفريق حالًا وهرد عليكي 🌸';

const HT_ALL_DAY = ['days' => [0, 1, 2, 3, 4, 5, 6], 'from' => '10:00', 'to' => '22:00'];

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

/** Ingests one customer message without letting the bot answer it. */
function htSay(string $text): Conversation
{
    static $n = 0;
    $n++;
    BotSetting::current()->update(['enabled' => false]);
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-HT', 'Mona', 'ht'.$n.'-'.uniqid(), $text, CarbonImmutable::now(),
    ));
    BotSetting::current()->update(['enabled' => true]);

    return Conversation::firstOrFail()->fresh();
}

/**
 * One agent turn over everything she said, straight through TurnRunner. The BotEngine
 * hours gate is deliberately skipped: these tests are about the handover wording at
 * every hours setting, not about the closed-hours notice.
 */
function htTurn(): Conversation
{
    $c = Conversation::firstOrFail()->fresh();
    app(TurnRunner::class)->run($c, app(ReplyScheduler::class)->burst($c));

    return $c->fresh();
}

function htLastBot(): ?string
{
    return Message::where('sender_type', SenderType::Bot->value)->latest('id')->value('body');
}

/** @return list<string> */
function htBodies(): array
{
    return Message::where('sender_type', SenderType::Bot->value)->orderBy('id')->pluck('body')->map(fn ($b) => (string) $b)->all();
}

it('ends an angry-message handover on the agent path with the transfer sentence', function (?array $hours, string $at, string $expected) {
    BotFlow::query()->update(['is_active' => false]);
    BotSetting::current()->update(['working_hours' => $hours]);
    $this->travelTo(CarbonImmutable::parse($at, 'Africa/Cairo'));

    htSay('انا زعلانه جدا ردوا بسرعه');
    $c = htTurn();

    expect($c->handler)->toBe(Handler::Human)
        ->and($c->handover_category)->toBe('angry_or_urgent')
        ->and(htLastBot())->toBe($expected)
        ->and(htBodies())->not->toContain(HT_OLD_ACK);
})->with([
    'no hours set' => [null, '2026-09-19 12:00', HT_NO_HOURS],
    'inside hours' => [HT_ALL_DAY, '2026-09-19 12:00', HT_IN_HOURS],
    'after closing' => [HT_ALL_DAY, '2026-09-19 23:30', HT_AFTER_HOURS],
]);

it('ends a flow handover with the same transfer sentence', function (?array $hours, string $at, string $expected) {
    BotSetting::current()->update(['working_hours' => $hours]);
    htSay('هاي');
    $this->travelTo(CarbonImmutable::parse($at, 'Africa/Cairo'));

    $c = Conversation::firstOrFail()->fresh();
    app(FlowEngine::class)->runPayload($c, 'handover:now');

    expect($c->fresh()->handler)->toBe(Handler::Human)
        ->and(htLastBot())->toBe($expected);
})->with([
    'no hours set' => [null, '2026-09-19 12:00', HT_NO_HOURS],
    'inside hours' => [HT_ALL_DAY, '2026-09-19 12:00', HT_IN_HOURS],
    'after closing' => [HT_ALL_DAY, '2026-09-19 23:30', HT_AFTER_HOURS],
]);

it('keeps a handover intent’s own script and adds the transfer sentence under it', function () {
    BotFlow::query()->update(['is_active' => false]);

    htSay('ممكن تعملولي الاوردر انتوا');
    $c = htTurn();

    expect($c->handover_category)->toBe('order_via_agent')
        ->and(htLastBot())->toBe('تمام يا فندم، استني ثواني هحولك لموظف يسجل الأوردر مع حضرتك 🌸'."\n".HT_NO_HOURS);
});

it('says nothing at all when the reply window is closed', function () {
    BotFlow::query()->update(['is_active' => false]);

    htSay('التوصيل بياخد كام يوم؟');
    // The 24h Messenger window is over: nothing can be sent, so nothing is (no transfer sentence either).
    Conversation::firstOrFail()->forceFill(['last_customer_message_at' => now()->subDays(3)])->save();

    $c = htTurn();

    expect($c->handler)->toBe(Handler::Human)
        ->and($c->handover_category)->toBe('window_closed')
        ->and(htBodies())->toBe([]);
});

it('says nothing either when a handover of its own runs into the closed window', function () {
    BotFlow::query()->update(['is_active' => false]);

    htSay('الفروع فين؟'); // branches_hours: no active script, so the turn hands over with nothing said
    Conversation::firstOrFail()->forceFill(['last_customer_message_at' => now()->subDays(3)])->save();

    expect(htTurn()->handler)->toBe(Handler::Human)->and(htBodies())->toBe([]);
});

it('keeps the delayed-response line once on a repeated handover, then the transfer sentence', function () {
    BotFlow::query()->update(['is_active' => false]);

    htSay('الفروع فين؟');
    Conversation::firstOrFail()->forceFill(['bot_state' => ['last_intents' => ['branches_hours'], 'repeat_count' => 2, 'last_answered' => false]])->save();

    $c = htTurn();
    $body = (string) htLastBot();

    expect($c->handover_category)->toBe('repeated')
        ->and($body)->toContain('في ضغط في الرسايل')
        ->and($body)->toEndWith(HT_NO_HOURS)
        ->and($c->bot_state['delayed_response_sent'])->toBeTrue()
        ->and($body)->not->toContain(HT_OLD_ACK);
});

it('drops the delayed-response line on the next repeated handover but still transfers her', function () {
    BotFlow::query()->update(['is_active' => false]);

    htSay('الفروع فين؟');
    Conversation::firstOrFail()->forceFill(['bot_state' => ['last_intents' => ['branches_hours'], 'repeat_count' => 2, 'last_answered' => false, 'delayed_response_sent' => true]])->save();

    htTurn();

    expect(htLastBot())->toBe(HT_NO_HOURS);
});

it('sends nothing when the owner turns the transfer scripts off', function () {
    BotFlow::query()->update(['is_active' => false]);
    BotKnowledgeEntry::whereIn('key', ['script.handover_in_hours', 'script.handover_no_hours', 'script.handover_after_hours'])->update(['is_active' => false]);

    htSay('انا زعلانه جدا ردوا بسرعه');
    $c = htTurn();

    expect($c->handler)->toBe(Handler::Human)->and(htBodies())->toBe([]);
});

it('keeps script.handover_ack in the catalogue even though it is no longer the transfer promise', function () {
    expect(BotKnowledgeEntry::where('key', 'script.handover_ack')->value('body'))->toBe(HT_OLD_ACK);
});
