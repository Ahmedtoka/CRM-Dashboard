<?php

use App\Bot\Ai\SonnetBudget;
use App\Bot\Flows\FlowPrompter;
use App\Bot\Flows\FlowState;
use App\Bot\Router\TaskQueue;
use App\Channels\Data\InboundMessageData;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Inbox\InboxIngestor;
use App\Models\BotRule;
use App\Models\BotRun;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

// Bot hybrid P2/P3 (bot-hybrid.md §3): tier 0, the planner, tier 1 / tier 2, the guards and the
// owner's B3/B5/B8 rules — every model call faked (never the real API in the suite).

beforeEach(function () {
    Event::fake();
    Http::preventStrayRequests();
    config([
        'crm.drivers.ai' => 'claude', 'crm.anthropic.key' => 'test-key', 'crm.bot.agent.enabled' => true,
        'crm.bot.router.mode' => 'hybrid', 'crm.bot.router.legacy_fallback' => false,
    ]);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => true, 'ai_enabled' => true, 'working_hours' => null]);
    BotRule::query()->update(['is_active' => false]);
    $this->ai = (object) ['log' => [], 'plans' => [], 'agent' => []];
});

/** Fakes the API: planner calls (structured output) and agent calls are answered from two queues. */
function hrFake(array $plans, array $agent): void
{
    test()->ai->plans = $plans;
    test()->ai->agent = $agent;

    Http::fake(['api.anthropic.com/*' => function (Request $request) {
        $body = $request->data();
        $planner = isset($body['output_config']['format']);
        test()->ai->log[] = ['kind' => $planner ? 'planner' : 'agent', 'body' => $body, 'headers' => $request->headers()];
        $next = $planner ? array_shift(test()->ai->plans) : array_shift(test()->ai->agent);

        return $next === null ? Http::response(['error' => 'none left'], 500) : Http::response($next);
    }]);
}

function hrPlan(array $tasks, array $extra = []): array
{
    $tasks = array_map(fn ($t) => $t + ['order_refs' => [], 'items' => [], 'governorate' => null, 'place' => null, 'product_query' => null, 'question' => null, 'confidence' => 0.9], $tasks);

    return ['stop_reason' => 'end_turn', 'model' => 'claude-haiku-5-5', 'usage' => ['input_tokens' => 300, 'output_tokens' => 60, 'cache_read_input_tokens' => 1200], 'content' => [
        ['type' => 'text', 'text' => json_encode($extra + ['tasks' => $tasks, 'sentiment' => 'neutral', 'wants_human' => false, 'continues_previous' => false, 'policy_edge' => false, 'clarify' => null], JSON_UNESCAPED_UNICODE)],
    ]];
}

function hrText(string $text, string $model = 'claude-haiku-5-5', string $stop = 'end_turn'): array
{
    return ['stop_reason' => $stop, 'model' => $model, 'usage' => ['input_tokens' => 400, 'output_tokens' => 40, 'cache_read_input_tokens' => 3000], 'content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => $text]]];
}

function hrTool(string $name, array $input, string $model = 'claude-haiku-5-5'): array
{
    return ['stop_reason' => 'tool_use', 'model' => $model, 'usage' => ['input_tokens' => 400, 'output_tokens' => 30], 'content' => [['type' => 'tool_use', 'id' => 'tu_'.uniqid(), 'name' => $name, 'input' => $input]]];
}

function hrSay(string $text): Conversation
{
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-HR', 'Mona', 'hr-'.uniqid(), $text, CarbonImmutable::now(),
    ));

    return Conversation::firstOrFail();
}

/** @return list<string> */
function hrBot(): array
{
    return Message::where('sender_type', SenderType::Bot->value)->orderBy('id')->pluck('body')->map(fn ($b) => (string) $b)->all();
}

function hrCalls(string $kind): array
{
    return array_values(array_filter(test()->ai->log, fn ($c) => $c['kind'] === $kind));
}

it('answers a standing question from the owner script at once, without any model (tier 0)', function () {
    hrFake([], []);

    hrSay('بتقبلوا انستاباي؟');

    expect(test()->ai->log)->toBe([])
        ->and(hrBot())->toBe([app(FlowPrompter::class)->script('payment_info')])
        ->and(BotRun::latest('id')->first()->only(['engine', 'tier']))->toBe(['engine' => 'hybrid', 'tier' => '0']);
});

it('plans with Haiku, then answers on tier 1 with a cache-friendly Haiku request', function () {
    hrFake([hrPlan([['kind' => 'shipping_fee', 'governorate' => 'الجيزة']])], [hrTool('get_shipping_fee', ['governorate' => 'الجيزة']), hrText('مصاريف الشحن للجيزة على الموقع يا فندم')]);

    hrSay('الشحن للجيزة بكام؟');

    $planner = hrCalls('planner')[0]['body'];
    $agent = hrCalls('agent')[0];

    expect($planner['model'])->toBe('claude-haiku-5-5')
        ->and($planner['thinking'])->toBe(['type' => 'disabled'])
        ->and($planner['output_config']['effort'])->toBe('low')
        ->and($planner)->not->toHaveKey('temperature')
        ->and($planner['system'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($agent['body']['model'])->toBe('claude-haiku-5-5')
        ->and($agent['body']['thinking'])->toBe(['type' => 'adaptive'])
        ->and($agent['body']['output_config'])->toBe(['effort' => 'low'])
        ->and($agent['body'])->not->toHaveKey('fallbacks')
        ->and($agent['body']['system'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and(collect($agent['body']['tools'])->pluck('name')->all())->toContain('get_order_items', 'escalate', 'start_flow')
        ->and(end($agent['body']['messages'])['content'][0]['text'])->toContain('CONTEXT')->toContain('shipping_fee')
        ->and(end($agent['body']['messages'])['content'][1]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($agent['body']['system'][0]['text'])->not->toContain('Messenger')
        ->and(hrBot())->toBe(['مصاريف الشحن للجيزة على الموقع يا فندم']);

    $run = BotRun::latest('id')->first();
    expect($run->engine)->toBe('hybrid')
        ->and($run->tier)->toBe('1')
        ->and($run->rounds)->toBe(2)
        ->and((float) $run->cost_usd)->toBeGreaterThan(0);
});

it('sends a policy edge to Sonnet 5.5 with the server-side refusal fallback (E3)', function () {
    hrFake([hrPlan([['kind' => 'return', 'order_refs' => ['209129']]], ['policy_edge' => true])], [hrText('حقك علينا يا فندم، هنراجع الطلب', 'claude-sonnet-5-5')]);

    hrSay('الاوردر 209129 اتسلم من 20 يوم وعايزة ارجعه');

    $agent = hrCalls('agent')[0];
    expect($agent['body']['model'])->toBe('claude-sonnet-5-5')
        ->and($agent['body']['fallbacks'])->toBe('default')
        ->and($agent['headers']['anthropic-beta'][0])->toBe('server-side-fallback-2026-07-01')
        ->and($agent['body']['output_config'])->toBe(['effort' => 'medium'])
        ->and(FlowState::flow(Conversation::first())['key'] ?? null)->toBe('return_exchange')
        ->and(BotRun::latest('id')->first()->tier)->toBe('2');
});

it('runs tier 2 on Haiku once the day\'s Sonnet spend reaches the cap (B3)', function () {
    Cache::put('bot:sonnet-spend:'.now('Africa/Cairo')->toDateString(), 5_100_000, now()->addDay());
    expect(app(SonnetBudget::class)->exceeded())->toBeTrue();

    hrFake([hrPlan([['kind' => 'policy', 'question' => 'ينفع ارجع حاجة من الفرع اونلاين']], ['policy_edge' => true])], [hrText('أيوه ينفع يا فندم')]);

    hrSay('ينفع ارجع حاجة اشتريتها من الفرع اونلاين؟');

    expect(hrCalls('agent')[0]['body']['model'])->toBe('claude-haiku-5-5');
});

it('gives tier 1 one Sonnet retry when its reply invents a price (E5), and sends the checked one', function () {
    hrFake([hrPlan([['kind' => 'product', 'product_query' => 'فستان ستان']])], [
        hrText('الفستان بـ 777 جنيه يا فندم'),
        hrText('خليني أشوفلك المتاح يا فندم', 'claude-sonnet-5-5'),
    ]);

    hrSay('الفستان الستان ده بكام؟');

    expect(hrCalls('agent'))->toHaveCount(2)
        ->and(hrCalls('agent')[1]['body']['model'])->toBe('claude-sonnet-5-5')
        ->and(end(hrCalls('agent')[1]['body']['messages'])['content'][0]['text'])->toContain('failed the price check')
        ->and(hrBot())->toBe(['خليني أشوفلك المتاح يا فندم'])
        ->and(BotRun::latest('id')->first()->tier)->toBe('2');
});

it('never falls back to the old turn runner: a safe line and the offer of a person (B8)', function () {
    hrFake([hrPlan([['kind' => 'product']])], [hrText('ولو طلبتي النهارده ليكي هدية'), hrText('وكمان خصم 50% ليكي', 'claude-sonnet-5-5')]);

    $c = hrSay('اديني خصم 50% وانا اطلب');

    expect(hrCalls('agent'))->toHaveCount(2)
        ->and(implode(' ', hrBot()))->not->toContain('هدية')->not->toContain('50%')
        ->and(hrBot())->toBe([app(FlowPrompter::class)->script('bot_safe_fallback')])
        ->and(FlowState::confirm($c->refresh()))->toBe('handover_offer')
        ->and(BotRun::latest('id')->first()->decision)->toBe('safe_fallback');
});

it('hands more than four requests in one message to a person, with the list for the team (B5)', function () {
    hrFake([hrPlan([['kind' => 'order_status'], ['kind' => 'shipping_fee'], ['kind' => 'branches'], ['kind' => 'product'], ['kind' => 'policy']])], []);

    $c = hrSay('فين الاوردر وبكام الشحن وفين الفرع وعندكم عبايات وبتقبلوا فيزا');

    expect($c->refresh()->handler)->not->toBe(Handler::Bot)
        ->and(hrBot()[0])->toBe(app(FlowPrompter::class)->script('tasks_too_many'))
        ->and(hrCalls('agent'))->toBe([])
        ->and(BotRun::latest('id')->first()->tier)->toBe('handover');
});

it('starts a filled return flow with no model reply when the message is only that request (#21)', function () {
    hrFake([hrPlan([['kind' => 'return', 'order_refs' => ['209129'], 'items' => [['text' => 'الفستان الاسود', 'qty' => null, 'action' => 'return', 'exchange_to' => null], ['text' => 'الطرحة', 'qty' => null, 'action' => 'return', 'exchange_to' => null]]]])], []);

    $c = hrSay('عايزة ارجع الفستان الاسود والطرحة من اوردر 209129');

    $flow = FlowState::flow($c->refresh());
    expect(hrCalls('agent'))->toBe([])
        ->and($flow['key'])->toBe('return_exchange')
        ->and($flow['data']['request_kind'])->toBe('return')
        ->and(BotRun::latest('id')->first()->tier)->toBe('flow');
});

it('drops an order number the planner invented', function () {
    hrFake([hrPlan([['kind' => 'cancel', 'order_refs' => ['999999']]])], []);

    $c = hrSay('عايزة الغي الاوردر 209129');

    // Her own number replaces the invented one.
    expect(json_encode(TaskQueue::all($c->refresh())))->not->toContain('999999')
        ->and(TaskQueue::all($c)[0]['data'])->toBe(['order_ref_hint' => '209129'])
        ->and(FlowState::flow($c)['key'] ?? null)->toBe('cancel_edit');
});

it('plans with keywords when the Haiku planner fails, and still answers', function () {
    hrFake([], [hrText('تقدري تشوفي المقاسات على الموقع يا فندم')]);

    hrSay('عندكم مقاسات كبيرة؟');

    expect(hrCalls('planner'))->toHaveCount(1)
        ->and(hrBot())->toBe(['تقدري تشوفي المقاسات على الموقع يا فندم']);
});

it('lets tier 1 ask for tier 2 with the escalate tool', function () {
    hrFake([hrPlan([['kind' => 'other']])], [hrTool('escalate', ['reason' => 'dispute']), hrText('أكيد يا فندم هنراجع ده', 'claude-sonnet-5-5')]);

    hrSay('هو انا ليه اتحسب عليا الشحن مرتين في نفس الاوردر');

    expect(collect(hrCalls('agent'))->pluck('body.model')->all())->toBe(['claude-haiku-5-5', 'claude-sonnet-5-5'])
        ->and(hrBot())->toBe(['أكيد يا فندم هنراجع ده']);
});

it('sends nothing from a refused or cut-off answer', function () {
    hrFake([hrPlan([['kind' => 'other']])], [hrText('', stop: 'refusal'), hrText('نص مقطوع', 'claude-sonnet-5-5', 'max_tokens')]);

    hrSay('سؤال غريب');

    expect(hrBot())->toBe([app(FlowPrompter::class)->script('bot_safe_fallback')]);
});
