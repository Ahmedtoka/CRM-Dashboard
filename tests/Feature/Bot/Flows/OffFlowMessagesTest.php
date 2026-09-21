<?php

use App\Bot\BotEngine;
use App\Bot\Flow\ReplyScheduler;
use App\Bot\Flows\FakeFlowAnswerInterpreter;
use App\Bot\Flows\FlowAnswerInterpreter;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowResult;
use App\Bot\Flows\FlowState;
use App\Bot\Language\ConversationLanguage;
use App\Bot\Language\KeptNames;
use App\Channels\Data\InboundMessageData;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Inbox\InboxIngestor;
use App\Models\BotIntent;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Design 2026-09-21 §6: everything she writes in the middle of a flow that is not an
 * answer to the waiting step, in Arabic and in English. One test per case:
 *
 *   1 a question we can answer → the answer, then «نرجع لطلب المرتجع 🌸» and the step
 *     again; at most two detours, then a person (covered in ConversationRouterTest for
 *     the answering half, here for the return and the cap);
 *   2 she asks for another flow → «تحبي نسيب … ونتابع …؟» before anything is dropped;
 *   3 thanks or a greeting → one line, then the same step, and no failure counted;
 *   4 she asks for a person → the team, with what the flow collected;
 *   5 silence then a late message → «نكمل ولا نبدأ من جديد؟»;
 *   6 no flow and nothing matched → the main menu (BotEngine path).
 */
beforeEach(function () {
    Event::fake();
    Http::preventStrayRequests();
    config(['crm.drivers.ai' => 'fake']);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => false, 'working_hours' => null]);
    app()->bind(FlowAnswerInterpreter::class, FakeFlowAnswerInterpreter::class);
    KeptNames::reset();
    $this->travelTo(CarbonImmutable::parse('2026-09-19 12:00', 'Africa/Cairo'));
});

function offSay(string $text): Conversation
{
    static $n = 0;
    $n++;

    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-OFF', 'Mona', 'off'.$n.'-'.uniqid(), $text, CarbonImmutable::now(),
    ));

    return Conversation::firstOrFail();
}

function offTurn(string $text): FlowResult
{
    $c = offSay($text);
    $burst = app(ReplyScheduler::class)->burst($c);
    app(ConversationLanguage::class)->observe($c, $burst);
    KeptNames::reset();

    return app(FlowEngine::class)->handle($c->fresh(), $burst);
}

/** The complaint flow waiting on «الشكوى بخصوص إيه؟», in her language. */
function offStart(string $opener): Conversation
{
    $c = offSay($opener);
    app(ConversationLanguage::class)->observe($c, collect([$c->messages()->latest('id')->first()]));
    app(FlowEngine::class)->start($c->fresh(), 'complaint');

    return Conversation::firstOrFail();
}

function offLast(): Message
{
    return Message::where('sender_type', SenderType::Bot->value)->latest('id')->firstOrFail();
}

/** @return list<string> */
function offButtons(): array
{
    return array_column((array) offLast()->buttons, 'payload');
}

function offFlow(): ?array
{
    return FlowState::flow(Conversation::firstOrFail());
}

// ---- §6.2 she asks for another flow --------------------------------------------------------------

it('offers to leave the current flow when she asks for another one', function (string $opener, string $ask, bool $english) {
    offStart($opener);

    offTurn($ask);

    expect(Conversation::first()->bot_state['flow_confirm'])->toBe('switch:order_tracking')
        ->and(offButtons())->toBe(['yes', 'no'])
        ->and(offFlow()['key'])->toBe('complaint');

    if ($english) {
        expect(offLast()->body)->not->toMatch('/\p{Arabic}/u');
    }

    // «لأ نكمل» keeps her where she was, with the step asked again.
    offTurn($english ? 'no' : 'لأ');
    expect(offFlow()['key'])->toBe('complaint')
        ->and(Conversation::first()->bot_state['flow_confirm'] ?? null)->toBeNull();
})->with([
    'arabic' => ['اهلا', 'متابعة أوردر', false],
    'english' => ['Hello', 'Track order', true],
]);

it('drops the flow on yes and notes what it had already collected', function () {
    offStart('اهلا');
    offTurn('x');                       // a miss, so the flow has something on it
    offTurn('متابعة أوردر');
    offTurn('أيوه');

    expect(offFlow()['key'])->toBe('order_tracking')
        ->and(Conversation::first()->bot_state['flow_confirm'] ?? null)->toBeNull();
});

// ---- §6.3 thanks and greetings -------------------------------------------------------------------

it('answers thanks in one line and asks the same step again, without counting a failure', function (string $opener, string $thanks, bool $english) {
    offStart($opener);
    $before = Message::where('sender_type', SenderType::Bot->value)->count();

    offTurn($thanks);

    $new = Message::where('sender_type', SenderType::Bot->value)->orderBy('id')->get()->slice($before)->values();

    expect($new)->toHaveCount(2)
        ->and(offFlow()['retries'])->toBe(0)
        ->and(offFlow()['step'])->toBe('type')
        ->and(collect($new->last()->buttons)->pluck('payload')->all())->toContain('step:complaint:type:delivery');

    if ($english) {
        expect($new->pluck('body')->implode("\n"))->not->toMatch('/\p{Arabic}/u');
    }
})->with([
    'arabic' => ['اهلا', 'شكرا', false],
    'english' => ['Hello', 'thanks', true],
]);

// ---- §6.4 she asks for a person -------------------------------------------------------------------

it('hands her over mid-flow with what the flow collected', function (string $opener, string $ask) {
    BotIntent::query()->updateOrCreate(['key' => 'human_request'], ['is_active' => true, 'keywords' => ['اكلم موظف', 'عايزة اكلم حد', 'talk to a person', 'human']]);
    offStart($opener);
    offTurn('x');

    $result = offTurn($ask);

    expect($result->handled)->toBeTrue()
        ->and(Conversation::first()->handler)->toBe(Handler::Human)
        ->and(offFlow())->toBeNull();
})->with([
    'arabic' => ['اهلا', 'عايزة اكلم حد'],
    'english' => ['Hello', 'talk to a person'],
]);

// ---- §6.5 silence, then a late message ---------------------------------------------------------------

it('offers to carry on or start over after a long silence', function (string $opener, string $late, bool $english) {
    offStart($opener);

    $this->travelTo(CarbonImmutable::parse('2026-09-19 13:30', 'Africa/Cairo'));
    offTurn($late);

    expect(Conversation::first()->bot_state['flow_confirm'])->toBe('resume')
        ->and(offButtons())->toBe(['resume:continue', 'resume:restart']);

    if ($english) {
        expect(offLast()->body)->not->toMatch('/\p{Arabic}/u');
    }

    // «نكمل» — typed in Arabic, tapped in English (the button carries the payload).
    if ($english) {
        expect(app(FlowEngine::class)->runPayload(Conversation::firstOrFail(), 'resume:continue'))->toBeTrue();
    } else {
        offTurn('نكمل');
    }

    expect(offFlow()['step'])->toBe('type')
        ->and(offFlow()['retries'])->toBe(0)
        ->and(Conversation::first()->bot_state['flow_confirm'] ?? null)->toBeNull();
})->with([
    'arabic' => ['اهلا', 'ايه الأخبار', false],
    'english' => ['Hello', 'any news', true],
]);

it('starts the flow from the beginning on «ابدأ من جديد»', function () {
    offStart('اهلا');
    $this->travelTo(CarbonImmutable::parse('2026-09-19 13:30', 'Africa/Cairo'));
    offTurn('ايه الأخبار');

    $c = Conversation::firstOrFail();
    expect(app(FlowEngine::class)->runPayload($c, 'resume:restart'))->toBeTrue()
        ->and(offFlow()['step'])->toBe('type')
        ->and(offFlow()['retries'])->toBe(0);
});

// ---- §6.1 the detour cap ------------------------------------------------------------------------------

it('offers a person after two questions answered inside one flow', function () {
    offStart('اهلا');
    $c = Conversation::firstOrFail();
    $engine = app(FlowEngine::class);

    expect($engine->returnToFlow($c->fresh()))->toBeTrue()
        ->and($engine->returnToFlow(Conversation::firstOrFail()))->toBeTrue();

    // The third question in the same flow stops asking and offers a person instead.
    $engine->returnToFlow(Conversation::firstOrFail());

    expect(Conversation::first()->bot_state['flow_confirm'])->toBe('handover_offer')
        ->and(offButtons())->toBe(['yes', 'no']);
});

it('brings her back to the flow with its own name and no repeated question', function () {
    offStart('اهلا');
    $before = Message::where('sender_type', SenderType::Bot->value)->pluck('body')->all();

    app(FlowEngine::class)->returnToFlow(Conversation::firstOrFail());

    $last = offLast();
    expect($last->body)->toStartWith('نرجع لـ «الشكوى» 🌸')
        ->and($before)->not->toContain($last->body)
        ->and(collect($last->buttons)->pluck('payload')->all())->toContain('step:complaint:type:branch');
});

// ---- §6.6 nothing matched and no flow ------------------------------------------------------------------

it('shows the main menu instead of a dead end when nothing matched', function () {
    BotSetting::current()->update(['enabled' => true, 'ai_enabled' => true, 'working_hours' => null]);

    $c = offSay('بلابلا كدهولا زمبليطة');
    app(BotEngine::class)->handleTurn($c, app(ReplyScheduler::class)->burst($c));

    expect(Conversation::first()->handler)->toBe(Handler::Bot)
        ->and(offFlow()['key'])->toBe('main_menu')
        ->and(offLast()->body)->toContain('أقدر أساعدك في')
        ->and(collect(offLast()->buttons)->pluck('payload')->all())->toContain('flow:return_exchange')
        ->and(ConversationNote::count())->toBe(0);
});
