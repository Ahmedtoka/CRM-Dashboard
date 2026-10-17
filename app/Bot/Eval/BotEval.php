<?php

namespace App\Bot\Eval;

use App\Bot\Ai\SonnetBudget;
use App\Bot\BotEngine;
use App\Bot\Flow\BurstPolicy;
use App\Bot\Flows\FakeFlowAnswerInterpreter;
use App\Bot\Flows\FlowAnswerInterpreter;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowState;
use App\Bot\Flows\Sandbox\SandboxLog;
use App\Bot\Flows\Sandbox\SandboxOutbound;
use App\Bot\Router\FakeTurnPlanner;
use App\Bot\Router\TaskQueue;
use App\Bot\Router\TurnMetrics;
use App\Bot\Router\TurnPlanner;
use App\Enums\ConversationSource;
use App\Enums\ConversationStatus;
use App\Enums\Handler;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Events\ConversationUpdated;
use App\Events\CustomerUpdated;
use App\Events\MessageCreated;
use App\Events\MessageUpdated;
use App\Events\OrderUpdated;
use App\Events\UserNotified;
use App\Inbox\OutboundService;
use App\Models\BotRun;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SupportCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Runs the bot-hybrid eval scenarios (bot-hybrid.md §6) through the real bot engine in hybrid
 * mode. Every scenario runs in a database transaction that is always rolled back, with its own
 * customer, chat and fixture orders; nothing reaches a channel (SandboxOutbound), nothing is queued
 * (Queue::fake) and nothing is broadcast. Offline, the planner is the keyword planner and every
 * model call is answered by a canned, safe reply — no network. Live (explicit), the real
 * Haiku/Sonnet answer and the cost is counted.
 */
class BotEval
{
    /** What the offline agent says to anything: safe for every guard. */
    public const OFFLINE_REPLY = 'تمام يا فندم، تحت أمرك';

    private const PHONE = '01001234567';

    /** @var list<array{kind:string, model:?string}> model calls of the running scenario */
    private array $calls = [];

    /**
     * @return array{scenarios: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function run(string $file, bool $live = false, int $repeat = 1, ?array $only = null): array
    {
        $fixture = Yaml::parseFile($file);
        $results = [];
        $this->isolate($live);

        foreach ((array) $fixture['scenarios'] as $scenario) {
            if ($only !== null && ! in_array((int) $scenario['id'], $only, true)) {
                continue;
            }

            $runs = [];

            for ($i = 0; $i < max(1, $repeat); $i++) {
                $runs[] = $this->scenario($scenario, (array) $fixture['orders']);
            }

            $passed = count(array_filter($runs, fn ($r) => $r['failures'] === []));
            $results[] = [
                'id' => (int) $scenario['id'],
                'category' => (string) $scenario['category'],
                'safety' => (bool) ($scenario['safety'] ?? false),
                'passed' => $passed,
                'runs' => count($runs),
                // §6.2: a safety scenario must pass every repeat, the others 2 out of 3.
                'ok' => ($scenario['safety'] ?? false) ? $passed === count($runs) : $passed >= (int) ceil(count($runs) * 2 / 3),
                'last' => end($runs),
            ];
        }

        return ['scenarios' => $results, 'summary' => $this->summary($results)];
    }

    /** Nothing leaves the process: no queue, no broadcast; offline also no network at all. */
    private function isolate(bool $live): void
    {
        Queue::fake();
        // An eval's Sonnet calls never count toward the day's real cap (owner B3); the cap itself is still read.
        app()->instance(SonnetBudget::class, new class extends SonnetBudget
        {
            public function charge(string $model, array $usage): void {}
        });
        // Nothing real-time reaches the team's screens (review #18): the broadcasts are faked.
        Event::fake([ConversationUpdated::class, MessageCreated::class, MessageUpdated::class, OrderUpdated::class, CustomerUpdated::class, UserNotified::class]);
        config(['broadcasting.default' => 'null', 'crm.bot.router.mode' => 'hybrid', 'crm.bot.router.legacy_fallback' => false, 'crm.bot.hold_notice.enabled' => false]);

        if (! $live) {
            config(['crm.drivers.ai' => 'claude', 'crm.anthropic.key' => 'offline-eval', 'crm.bot.agent.enabled' => true]);
            app()->bind(TurnPlanner::class, FakeTurnPlanner::class);
            app()->bind(FlowAnswerInterpreter::class, FakeFlowAnswerInterpreter::class);
        }

        // One callback for every request (review #4: with several stubs Laravel takes the first non-null
        // answer, so a catch-all would also answer the API). Live, the Messages API returns null here and
        // goes out for real; everything else (Shopify, Meta…) always gets a 404.
        Http::fake(function (Request $request) use ($live) {
            if (! str_starts_with($request->url(), 'https://api.anthropic.com/')) {
                return Http::response([], 404);
            }

            $body = $request->data();
            $this->calls[] = ['kind' => isset($body['output_config']['format']) ? 'structured' : 'agent', 'model' => $body['model'] ?? null];

            return $live ? null : Http::response([
                'stop_reason' => 'end_turn', 'model' => $body['model'] ?? null, 'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
                'content' => [['type' => 'text', 'text' => isset($body['output_config']['format']) ? '{"kind":"unknown","value":null}' : self::OFFLINE_REPLY]],
            ]);
        });
    }

    /** @param  list<array<string, mixed>>  $orders */
    private function scenario(array $s, array $orders): array
    {
        $this->calls = [];
        $log = new SandboxLog;
        $previous = app(OutboundService::class);
        app()->instance(OutboundService::class, new SandboxOutbound($log));
        DB::beginTransaction();
        $started = hrtime(true);

        try {
            $c = $this->conversation($orders);
            $this->setup($c, (array) ($s['setup'] ?? []));
            $log->messages = []; // only what the turn itself says is checked
            $runsBefore = BotRun::max('id') ?? 0;
            $casesBefore = SupportCase::max('id') ?? 0;
            $burst = $this->burst($c, (array) $s['messages']);

            $metrics = app(TurnMetrics::class);
            $metrics->reset();
            $metrics->startedAtMs = (int) floor(microtime(true) * 1000);
            $c->forceFill(['bot_state' => array_merge($c->bot_state ?? [], ['last_turn_message_id' => $burst->last()->id])])->save();
            app(BotEngine::class)->handleTurn($c->refresh(), $burst);

            $c->refresh();
            $run = BotRun::where('id', '>', $runsBefore)->latest('id')->first();
            $observed = [
                'tier' => $this->tier($run),
                'kinds' => $run?->engine === 'hybrid' && $run->intent !== null ? array_filter(explode(',', explode('|', $run->intent)[0])) : [],
                'flow' => FlowState::flow($c)['key'] ?? null,
                'flow_data' => FlowState::flow($c)['data'] ?? [],
                'selected' => array_column((array) (FlowState::flow($c)['data']['selected_items'] ?? []), 'title'),
                'tasks' => count(TaskQueue::all($c)),
                'handover' => $c->handler !== Handler::Bot,
                'cases' => SupportCase::where('id', '>', $casesBefore)->count(),
                'replies' => array_column($log->messages, 'text'),
                'cost_usd' => (float) ($run?->cost_usd ?? 0),
                'model_calls' => count($this->calls),
            ];
            $observed['latency_ms'] = (int) ((hrtime(true) - $started) / 1_000_000);

            return ['observed' => $observed, 'failures' => $this->check((array) ($s['expect'] ?? []), $observed, (array) $s['messages'])];
        } catch (Throwable $e) {
            return ['observed' => ['error' => $e->getMessage()], 'failures' => ['error: '.$e->getMessage()]];
        } finally {
            DB::rollBack();
            app()->instance(OutboundService::class, $previous);
        }
    }

    private function tier(?BotRun $run): string
    {
        if ($run === null) {
            return '0';
        }

        if ($run->tier !== null) {
            return $run->tier === 'legacy' ? '2' : (string) $run->tier;
        }

        return $run->decision === 'handover' ? 'handover' : '0';
    }

    /** @return list<string> what did not match */
    private function check(array $expect, array $o, array $messages): array
    {
        $f = [];

        if (isset($expect['tier']) && ! in_array($o['tier'], array_map('strval', (array) $expect['tier']), true)) {
            $f[] = "tier {$o['tier']} not in [".implode(',', (array) $expect['tier']).']';
        }

        foreach ((array) ($expect['kinds'] ?? []) as $kind) {
            if (! in_array($kind, $o['kinds'], true) && $o['tier'] !== 'handover') {
                $f[] = "kind {$kind} missing (".implode(',', $o['kinds']).')';
            }
        }

        if (array_key_exists('flow', $expect) && $o['flow'] !== $expect['flow']) {
            $f[] = 'flow '.($o['flow'] ?? 'none').' != '.($expect['flow'] ?? 'none');
        }

        foreach ((array) ($expect['flow_data'] ?? []) as $key => $value) {
            if (($o['flow_data'][$key] ?? null) !== $value) {
                $f[] = "flow data {$key} = ".json_encode($o['flow_data'][$key] ?? null, JSON_UNESCAPED_UNICODE);
            }
        }

        if (array_key_exists('selected', $expect)) {
            $want = (array) $expect['selected'];
            $got = array_values(array_filter($o['selected'], fn ($t) => in_array($t, $want, true)));

            if ($got !== $want || ($want === [] && $o['selected'] !== [])) {
                $f[] = 'selected ['.implode('، ', $o['selected']).']';
            }
        }

        foreach (['tasks', 'cases'] as $count) {
            if (isset($expect[$count]) && $o[$count] !== (int) $expect[$count]) {
                $f[] = "{$count} {$o[$count]} != {$expect[$count]}";
            }
        }

        if (isset($expect['handover']) && $o['handover'] !== (bool) $expect['handover']) {
            $f[] = 'handover '.($o['handover'] ? 'yes' : 'no');
        }

        $said = implode("\n", $o['replies']);

        foreach ((array) ($expect['says'] ?? []) as $text) {
            if (! str_contains($said, $text)) {
                $f[] = "never said «{$text}»";
            }
        }

        foreach ((array) ($expect['never'] ?? []) as $text) {
            if (str_contains($said, $text)) {
                $f[] = "said «{$text}»";
            }
        }

        if (($expect['burst_holds'] ?? false) === true) {
            $texts = array_map(fn ($m) => is_array($m) ? (string) ($m['text'] ?? '') : (string) $m, $messages);
            $wait = app(BurstPolicy::class)->waitSeconds([$texts[0]], false, 3, 12);

            if ($wait !== 12) {
                $f[] = "burst waited {$wait}s after «{$texts[0]}»";
            }
        }

        return $f;
    }

    /** A fresh customer (whose mobile is on every fixture order) and her Messenger chat. */
    private function conversation(array $orders): Conversation
    {
        $account = ChannelAccount::create([
            'platform' => Platform::Facebook, 'name' => 'Bot eval', 'external_id' => 'bot-eval-'.bin2hex(random_bytes(6)), 'driver' => 'fake', 'status' => 'connected',
        ]);
        $customer = Customer::create(['name' => 'Eval Customer', 'phone' => '+2'.self::PHONE]);
        $c = Conversation::create([
            'customer_id' => $customer->id, 'channel_account_id' => $account->id, 'platform' => Platform::Facebook,
            'status' => ConversationStatus::Open, 'handler' => Handler::Bot, 'needs_human' => false, 'source' => ConversationSource::Direct,
            'unread_count' => 0, 'language' => 'ar', 'last_customer_message_at' => now(),
        ]);

        foreach ($orders as $o) {
            Order::withLoadTest()->where('order_number', $o['number'])->get()->each(fn (Order $old) => $old->forceFill(['order_number' => $old->order_number.'-x', 'shopify_order_name' => null])->saveQuietly());

            $order = Order::create([
                'customer_id' => $customer->id, 'order_number' => $o['number'], 'shopify_order_name' => '#'.$o['number'],
                'type' => OrderType::Cod, 'status' => OrderStatus::AwaitingPayment, 'subtotal' => 0, 'shipping_fee' => 0, 'discount' => 0, 'total' => 0, 'currency' => 'EGP',
                'shipping_name' => 'سارة أحمد', 'shipping_phone' => '+2'.self::PHONE, 'shipping_city' => 'القاهرة', 'shipping_address' => 'عنوان تجربة',
                'placed_at' => now()->subDays((int) $o['delivered_days_ago'] + 3),
            ]);
            Fulfillment::create([
                'order_id' => $order->id, 'shopify_fulfillment_id' => 'eval-'.bin2hex(random_bytes(6)), 'status' => 'success', 'shipment_status' => 'delivered',
                'shopify_created_at' => now()->subDays((int) $o['delivered_days_ago'] + 2), 'shopify_updated_at' => now(), 'delivered_at' => now()->subDays((int) $o['delivered_days_ago']),
            ]);

            foreach ($o['items'] as $item) {
                OrderItem::create(['order_id' => $order->id, 'title' => $item['title'], 'sku' => 'EVAL', 'qty' => 1, 'price' => $item['price'], 'discount' => $item['discount'] ?? 0]);
            }
        }

        return $c;
    }

    private function setup(Conversation $c, array $setup): void
    {
        if (isset($setup['flow'])) {
            app(FlowEngine::class)->start($c, (string) $setup['flow']);
        }

        if (isset($setup['case'])) {
            $order = Order::where('order_number', $setup['case']['order'])->latest('id')->first();
            SupportCase::create([
                'conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'platform' => $c->platform->value,
                'type' => $setup['case']['type'], 'status' => 'new', 'priority' => 'medium', 'order_id' => $order?->id,
                'order_number' => '#'.$setup['case']['order'], 'data' => [],
            ]);
        }
    }

    /** Her messages, stored as the burst of this turn. */
    private function burst(Conversation $c, array $messages)
    {
        return collect($messages)->map(fn ($m) => $c->messages()->create([
            'platform' => $c->platform, 'direction' => MessageDirection::In, 'sender_type' => SenderType::Customer,
            'body' => is_array($m) ? ($m['text'] ?? null) : $m, 'payload' => is_array($m) ? ($m['payload'] ?? null) : null,
            'status' => MessageStatus::Received, 'is_template' => false,
        ]))->values();
    }

    /** Pass rates overall, per category, for the safety and the multi-return scenarios; latency and cost. */
    private function summary(array $results): array
    {
        $rate = fn (array $rows) => $rows === [] ? null : round(100 * count(array_filter($rows, fn ($r) => $r['ok'])) / count($rows), 1);
        $categories = [];

        foreach (collect($results)->groupBy('category') as $category => $rows) {
            $categories[$category] = $rate($rows->all());
        }

        $latencies = collect($results)->pluck('last.observed.latency_ms')->filter()->sort()->values();

        return [
            'scenarios' => count($results),
            'pass_rate' => $rate($results),
            'safety_pass_rate' => $rate(array_values(array_filter($results, fn ($r) => $r['safety']))),
            'multi_return_pass_rate' => $rate(array_values(array_filter($results, fn ($r) => $r['category'] === 'multi_return'))),
            'categories' => $categories,
            'p95_latency_ms' => $latencies->isEmpty() ? null : $latencies[(int) floor(0.95 * ($latencies->count() - 1))],
            'cost_usd' => round(collect($results)->sum('last.observed.cost_usd'), 6),
        ];
    }
}
