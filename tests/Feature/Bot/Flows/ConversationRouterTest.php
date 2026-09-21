<?php

use App\Bot\Flow\FakeTurnUnderstanding;
use App\Bot\Flow\TurnUnderstanding;
use App\Bot\Flows\FakeFlowAnswerInterpreter;
use App\Bot\Flows\FlowAnswer;
use App\Bot\Flows\FlowAnswerInterpreter;
use App\Bot\Flows\FlowState;
use App\Channels\Data\InboundMessageData;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Inbox\InboxIngestor;
use App\Models\BotFlow;
use App\Models\BotIntent;
use App\Models\BotRun;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SupportCase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    useOrderAwareReturnFlow();
    Event::fake();
    Http::preventStrayRequests();
    config(['crm.drivers.ai' => 'fake']);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => true, 'ai_enabled' => true, 'working_hours' => null, 'min_confidence' => 0.6]);
    app()->bind(TurnUnderstanding::class, FakeTurnUnderstanding::class);
    app()->bind(FlowAnswerInterpreter::class, FakeFlowAnswerInterpreter::class);
});

/** Ingests one customer message; the bot answers it through the normal turn pipeline. */
function routerSay(string $text, ?string $payload = null): Conversation
{
    static $n = 0;
    $n++;
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-1', 'Mona', 'cr'.$n.'-'.uniqid(), $text, CarbonImmutable::now(), payload: $payload,
    ));

    return Conversation::firstOrFail();
}

/** @return Collection<int, Message> */
function routerBotMessages()
{
    return Message::where('sender_type', SenderType::Bot->value)->orderBy('id')->get();
}

it('greets and shows the main menu with 7 buttons on a first "هاي"', function () {
    routerSay('هاي');

    $bot = routerBotMessages();
    expect($bot)->toHaveCount(2)
        ->and($bot[0]->body)->toContain('مع حضرتك ميار من Le Voile')
        ->and($bot[1]->buttons)->toHaveCount(7)
        ->and($bot[1]->buttons[0])->toBe(['title' => 'المرتجع والاستبدال', 'payload' => 'flow:return_exchange'])
        ->and(FlowState::flow(Conversation::first())['key'])->toBe('main_menu');

    $run = BotRun::latest('id')->first();
    expect($run->engine)->toBe('flow_engine')->and($run->decision)->toBe('menu')
        ->and(Conversation::first()->handler)->toBe(Handler::Bot);
});

it('shows only the menu for "منيو" later in the conversation', function () {
    routerSay('التوصيل بياخد كام يوم؟');
    $before = routerBotMessages()->count();

    routerSay('منيو');

    $new = routerBotMessages()->slice($before)->values();
    expect($new)->toHaveCount(1)
        ->and($new[0]->buttons)->toHaveCount(7)
        ->and($new[0]->body)->not->toContain('ميار');
});

it('routes a tapped flow button to that flow', function () {
    routerSay('هاي');
    routerSay('شكوى', payload: 'flow:complaint');

    $last = routerBotMessages()->last();
    expect(FlowState::flow(Conversation::first())['key'])->toBe('complaint')
        ->and(collect($last->buttons)->pluck('payload')->all())->toContain('step:complaint:type:branch', 'step:complaint:type:delivery');
});

it('starts the return flow from a free-text return request', function () {
    routerSay('عايزة ارجع الاوردر');

    $c = Conversation::first();
    $body = routerBotMessages()->pluck('body')->implode("\n");
    expect($c->bot_state['flow']['key'])->toBe('return_exchange')
        ->and($c->bot_state['flow']['step'])->toBe('order')
        ->and($body)->toContain('ممكن رقم الأوردر أو رقم الموبايل')
        ->and($c->handler)->toBe(Handler::Bot)
        ->and(BotRun::latest('id')->first()->decision)->toBe('flow_started')
        ->and(BotRun::latest('id')->first()->engine)->toBe('flow_engine');
});

it('adds the menu button to a direct answer and drops the offer of a human', function () {
    routerSay('التوصيل بياخد كام يوم؟');

    $bot = routerBotMessages();
    expect($bot->pluck('body')->implode("\n"))->toContain('3-5 ايام عمل')
        ->and($bot->last()->buttons)->toBe([['title' => 'القائمة 📋', 'payload' => 'menu:main_menu']])
        ->and($bot->pluck('body')->implode("\n"))->not->toContain('لو حابة أحولك');
});

it('answers a question inside a flow and asks the waiting step again', function () {
    $c = routerSay('عايزة ارجع الاوردر');
    FlowState::put($c->refresh(), ['key' => 'return_exchange', 'step' => 'reason', 'data' => ['order_ref_text' => 'x'], 'retries' => 0, 'started_at' => now()->toIso8601String()]);
    app()->instance(FlowAnswerInterpreter::class, new class implements FlowAnswerInterpreter
    {
        public function interpret(array $step, string $text, array $history): FlowAnswer
        {
            return new FlowAnswer('question');
        }
    });
    $before = routerBotMessages()->count();

    routerSay('التوصيل كام يوم');

    $new = routerBotMessages()->slice($before)->values();
    expect($new->pluck('body')->implode("\n"))->toContain('3-5 ايام عمل')
        ->and($new->last()->body)->toBe("نرجع لـطلب المرتجع 🌸\nإيه سبب المرتجع؟")
        ->and(collect($new->last()->buttons)->pluck('payload')->all())->toContain('step:return_exchange:reason:defective')
        ->and(Conversation::first()->bot_state['flow']['step'])->toBe('reason')
        ->and(Conversation::first()->handler)->toBe(Handler::Bot);
});

it('offers a person instead of restarting the flow when a return case was recorded in the last 24 hours', function () {
    $c = routerSay('التوصيل بياخد كام يوم؟');
    $case = SupportCase::factory()->returnExchange()->create(['conversation_id' => $c->id]);

    routerSay('عايزة ارجع الاوردر');

    $last = routerBotMessages()->last();
    $c->refresh();
    expect($last->body)->toContain("#{$case->id}")
        ->and($last->buttons)->toBe([['title' => 'أيوه', 'payload' => 'yes'], ['title' => 'لأ', 'payload' => 'no']])
        ->and(FlowState::flow($c))->toBe(null)
        ->and($c->bot_state['flow_confirm'])->toBe('handover_offer');

    routerSay('أيوه', payload: 'yes');
    expect(Conversation::first()->handler)->toBe(Handler::Human);
});

it('restarts the flow when the recorded case is older than 24 hours', function () {
    $c = routerSay('التوصيل بياخد كام يوم؟');
    SupportCase::factory()->returnExchange()->create(['conversation_id' => $c->id, 'created_at' => now()->subHours(30)]);

    routerSay('عايزة ارجع الاوردر');

    expect(FlowState::flow(Conversation::first())['key'])->toBe('return_exchange');
});

it('keeps the v2 collect path when the intent flow is inactive', function () {
    BotFlow::where('key', 'return_exchange')->update(['is_active' => false]);

    routerSay('عايزة ارجع الاوردر');

    $c = Conversation::first();
    expect(FlowState::flow($c))->toBe(null)
        ->and($c->bot_state['awaiting_intent'])->toBe('exchange_return');
});

it('seeds flow_key on the intents without touching an owner choice', function () {
    expect(BotIntent::where('key', 'defect')->value('flow_key'))->toBe('return_exchange')
        ->and(BotIntent::where('key', 'delivery_problem')->value('flow_key'))->toBe('complaint')
        ->and(BotIntent::where('key', 'edit_order')->value('flow_key'))->toBe('cancel_edit')
        ->and(BotIntent::where('key', 'no_update')->value('flow_key'))->toBe('order_tracking')
        ->and(BotIntent::where('key', 'availability_branch')->value('flow_key'))->toBe('branches')
        ->and(BotIntent::where('key', 'delivery_time')->value('flow_key'))->toBe(null);

    BotIntent::where('key', 'defect')->update(['flow_key' => 'complaint']);
    (require database_path('migrations/2026_09_17_100070_add_flow_key_to_bot_intents.php'))->up();

    expect(BotIntent::where('key', 'defect')->value('flow_key'))->toBe('complaint');
});

it('sends the menu after a "no" to the case-exists offer', function () {
    $c = routerSay('التوصيل بياخد كام يوم؟');
    SupportCase::factory()->returnExchange()->create(['conversation_id' => $c->id]);
    routerSay('عايزة ارجع الاوردر');

    routerSay('لأ', payload: 'no');

    expect(routerBotMessages()->last()->buttons)->toHaveCount(7)
        ->and(Conversation::first()->handler)->toBe(Handler::Bot);
});

it('hands over when guided-flow turns reach three times the turn limit', function () {
    BotSetting::current()->update(['max_bot_turns' => 2]);

    // Distinct texts: the same text repeated is classified as spam and never reaches the bot.
    foreach (['هاي', 'منيو', 'القائمة', 'menu', 'القايمة', 'المنيو'] as $text) {
        routerSay($text);
    }
    expect(Conversation::first()->handler)->toBe(Handler::Bot)
        ->and(BotRun::where('engine', 'flow_engine')->count())->toBe(6);

    routerSay('منيو');

    expect(Conversation::first()->handler)->toBe(Handler::Human)
        ->and(BotRun::latest('id')->value('engine'))->toBe('limit');
});
