<?php

use App\Channels\Data\InboundMessageData;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

// «ليه البوت رد كده؟» (b2): each bot run gets a trace — the planner's tasks, every model call, the
// agent's tools with their inputs and results, the guards, the handover and the bot messages it sent.
// Every model call is faked (never the real API in the suite).

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'crm.drivers.ai' => 'claude', 'crm.anthropic.key' => 'test-key', 'crm.bot.agent.enabled' => true,
        'crm.bot.router.mode' => 'hybrid', 'crm.bot.router.legacy_fallback' => false, 'crm.bot.hold_notice.enabled' => false,
    ]);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => true, 'ai_enabled' => true, 'working_hours' => null]);
    BotRule::query()->update(['is_active' => false]);
    $this->queue = (object) ['plans' => [], 'agent' => []];
});

function btFake(array $plans, array $agent): void
{
    test()->queue->plans = $plans;
    test()->queue->agent = $agent;

    Http::fake(['api.anthropic.com/*' => function (Request $request) {
        $next = isset($request->data()['output_config']['format']) ? array_shift(test()->queue->plans) : array_shift(test()->queue->agent);

        return $next === null ? Http::response(['error' => 'none left'], 500) : Http::response($next);
    }]);
}

function btPlan(array $tasks, array $extra = []): array
{
    $tasks = array_map(fn ($t) => $t + ['order_refs' => [], 'items' => [], 'governorate' => null, 'place' => null, 'product_query' => null, 'question' => null, 'confidence' => 0.9], $tasks);

    return ['stop_reason' => 'end_turn', 'model' => 'claude-haiku-5-5', 'usage' => ['input_tokens' => 300, 'output_tokens' => 60, 'cache_read_input_tokens' => 1200], 'content' => [
        ['type' => 'text', 'text' => json_encode($extra + ['tasks' => $tasks, 'sentiment' => 'neutral', 'wants_human' => false, 'continues_previous' => false, 'policy_edge' => false, 'clarify' => null], JSON_UNESCAPED_UNICODE)],
    ]];
}

function btText(string $text, string $model = 'claude-haiku-5-5'): array
{
    return ['stop_reason' => 'end_turn', 'model' => $model, 'usage' => ['input_tokens' => 400, 'output_tokens' => 40, 'cache_read_input_tokens' => 3000], 'content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => $text]]];
}

function btTool(string $name, array $input): array
{
    return ['stop_reason' => 'tool_use', 'model' => 'claude-haiku-5-5', 'usage' => ['input_tokens' => 400, 'output_tokens' => 30], 'content' => [['type' => 'tool_use', 'id' => 'tu_'.uniqid(), 'name' => $name, 'input' => $input]]];
}

function btSay(string $text): Conversation
{
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-BT', 'Mona', 'bt-'.uniqid(), $text, CarbonImmutable::now(),
    ));

    return Conversation::firstOrFail();
}

/** @return array<string, mixed> */
function btTrace(): array
{
    $row = DB::table('bot_reply_traces')->latest('id')->first();

    return ['trace' => json_decode($row->trace, true), 'message_ids' => json_decode($row->message_ids, true), 'bot_run_id' => $row->bot_run_id];
}

it('records the plan, every model call, the tool with its input and result, and the guard verdict', function () {
    btFake([btPlan([['kind' => 'shipping_fee', 'governorate' => 'الجيزة']])], [btTool('get_shipping_fee', ['governorate' => 'الجيزة']), btText('مصاريف الشحن للجيزة على الموقع يا فندم')]);

    btSay('الشحن للجيزة بكام؟');

    $t = btTrace();
    $run = BotRun::latest('id')->first();
    $botMessage = Message::where('sender_type', SenderType::Bot->value)->latest('id')->first();

    expect($t['bot_run_id'])->toBe($run->id)
        ->and($t['trace']['router'])->toBe('hybrid')
        ->and($t['trace']['plan']['tasks'][0]['kind'])->toBe('shipping_fee')
        ->and(array_column($t['trace']['calls'], 'phase'))->toBe(['planner', 'agent', 'agent'])
        ->and($t['trace']['calls'][0]['model'])->toBe('claude-haiku-5-5')
        ->and($t['trace']['calls'][0]['cache_read'])->toBe(1200)
        ->and($t['trace']['calls'][0]['cost'])->toBeGreaterThan(0)
        ->and($t['trace']['tools'][0]['name'])->toBe('get_shipping_fee')
        ->and($t['trace']['tools'][0]['input'])->toContain('الجيزة')
        ->and($t['trace']['tools'][0]['output'])->not->toBeNull()
        ->and($t['trace']['guards'])->toBe(['pass'])
        ->and($t['trace']['handover'])->toBeNull()
        ->and($t['message_ids'])->toContain($botMessage->id);
});

it('records a handover with its reason and where the chat went', function () {
    btFake([btPlan([['kind' => 'complaint']], ['wants_human' => true, 'sentiment' => 'angry'])], []);

    btSay('انا عايزة اكلم حد حالا الاوردر بايظ');

    $t = btTrace();

    expect($t['trace']['handover'])->not->toBeNull()
        ->and($t['trace']['handover']['reason'])->not->toBeNull()
        ->and($t['trace']['handover']['queue'])->toBe('agents');
});

it('writes a trace for a turn answered without any model (tier 0 script)', function () {
    btFake([], []);

    btSay('بتقبلوا انستاباي؟');

    $t = btTrace();

    expect($t['trace']['calls'])->toBe([])
        ->and($t['trace']['plan'])->toBeNull()
        ->and($t['message_ids'])->toHaveCount(1);
});

it('never breaks the turn when the trace cannot be written', function () {
    Schema::drop('bot_reply_traces');
    btFake([], []);

    btSay('بتقبلوا انستاباي؟');

    expect(BotRun::count())->toBe(1)
        ->and(Message::where('sender_type', SenderType::Bot->value)->count())->toBe(1);
});

it('goes when its bot run goes (retention and erasure)', function () {
    btFake([], []);
    btSay('بتقبلوا انستاباي؟');

    BotRun::query()->delete();

    expect(DB::table('bot_reply_traces')->count())->toBe(0);
});
