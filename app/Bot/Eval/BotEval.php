<?php

namespace App\Bot\Eval;

use App\Bot\Ai\SonnetBudget;
use App\Bot\BotEngine;
use App\Bot\Brain\Feedback\FeedbackEvalCases;
use App\Bot\Episodes\ConversationEpisode;
use App\Bot\Flow\BurstPolicy;
use App\Bot\Flow\ReplyScheduler;
use App\Bot\Flow\TurnSupersede;
use App\Bot\Flow\TurnSuperseded;
use App\Bot\Flows\FakeFlowAnswerInterpreter;
use App\Bot\Flows\FlowAnswerInterpreter;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowState;
use App\Bot\Flows\Sandbox\SandboxLog;
use App\Bot\Flows\Sandbox\SandboxOutbound;
use App\Bot\HandoverSummary;
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
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Customer;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\QueueEntry;
use App\Models\SupportCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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

    /** @var list<array{kind:string, model:?string, messages:list<array<string, mixed>>}> model calls of the running scenario */
    private array $calls = [];

    /** Round b1: what the offline agent says in this scenario (`offline_reply`), and a message landing mid-call. */
    private ?string $offlineReply = null;

    private ?string $lateMessage = null;

    private ?Conversation $conversation = null;

    /** @var array<string, int> fixture piece titles → their order item ids (for `{item:<title>}` payloads) */
    private array $items = [];

    /**
     * @return array{scenarios: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function run(string $file, bool $live = false, int $repeat = 1, ?array $only = null): array
    {
        $fixture = Yaml::parseFile($file);
        // b2: the scenarios approved from staff feedback run after the file's own.
        $fixture['scenarios'] = [...(array) ($fixture['scenarios'] ?? []), ...FeedbackEvalCases::scenarios()];
        $results = [];
        $this->isolate($live);

        foreach ((array) $fixture['scenarios'] as $scenario) {
            if ($only !== null && ! in_array((int) $scenario['id'], $only, true)) {
                continue;
            }

            // Round 4: a scenario that needs a canned model answer (a guard that must fail) cannot run on the real model.
            if ($live && ($scenario['offline_only'] ?? false) === true) {
                continue;
            }

            // Round b1: a scenario may run on both routers (`routers: [legacy, hybrid]`), one result each.
            foreach ((array) ($scenario['routers'] ?? ['hybrid']) as $router) {
                $runs = [];

                for ($i = 0; $i < max(1, $repeat); $i++) {
                    $runs[] = $this->scenario($scenario, (array) $fixture['orders'], (string) $router);
                }

                $passed = count(array_filter($runs, fn ($r) => $r['failures'] === []));
                $results[] = [
                    'id' => (int) $scenario['id'],
                    'router' => (string) $router,
                    'category' => (string) $scenario['category'],
                    'safety' => (bool) ($scenario['safety'] ?? false),
                    'passed' => $passed,
                    'runs' => count($runs),
                    // §6.2: a safety scenario must pass every repeat, the others 2 out of 3.
                    'ok' => ($scenario['safety'] ?? false) ? $passed === count($runs) : $passed >= (int) ceil(count($runs) * 2 / 3),
                    'last' => end($runs),
                ];
            }
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
            $structured = isset($body['output_config']['format']);
            $this->calls[] = ['kind' => $structured ? 'structured' : 'agent', 'model' => $body['model'] ?? null, 'messages' => $structured ? [] : (array) ($body['messages'] ?? [])];

            // Round b1: her next message lands while the model is still thinking (problem 2).
            if (! $structured && $this->lateMessage !== null && $this->conversation !== null) {
                $this->storeCustomerMessage($this->conversation, $this->lateMessage);
                $this->lateMessage = null;
            }

            return $live ? null : Http::response([
                'stop_reason' => 'end_turn', 'model' => $body['model'] ?? null, 'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
                'content' => [['type' => 'text', 'text' => $structured ? '{"kind":"unknown","value":null}' : ($this->offlineReply ?? self::OFFLINE_REPLY)]],
            ]);
        });
    }

    /** @param  list<array<string, mixed>>  $orders */
    private function scenario(array $s, array $orders, string $router = 'hybrid'): array
    {
        $this->calls = [];
        $this->offlineReply = isset($s['offline_reply']) ? (string) $s['offline_reply'] : null;
        $this->lateMessage = isset($s['setup']['during_model']) ? (string) $s['setup']['during_model'] : null;
        config(['crm.bot.router.mode' => $router]);
        $log = new SandboxLog;
        $previous = app(OutboundService::class);
        app()->instance(OutboundService::class, new SandboxOutbound($log));
        DB::beginTransaction();
        $started = hrtime(true);

        try {
            $c = $this->conversation($orders);
            $this->conversation = $c;
            $this->setup($c, (array) ($s['setup'] ?? []));
            $log->messages = []; // only what the turn itself says is checked
            $runsBefore = BotRun::max('id') ?? 0;
            $casesBefore = SupportCase::max('id') ?? 0;
            $notesBefore = ConversationNote::max('id') ?? 0;
            $marker = ($c->refresh()->bot_state ?? [])['last_turn_message_id'] ?? null;
            $burst = $this->burst($c, (array) $s['messages']);
            $superseded = 0;

            // Round b1: a turn superseded by a message she sent meanwhile is answered again with both (RunBotTurn).
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $this->startTurn($c, $burst);

                try {
                    app(BotEngine::class)->handleTurn($c->refresh(), $burst);

                    break;
                } catch (TurnSuperseded) {
                    $superseded++;
                    $c->refresh()->forceFill(['bot_state' => array_merge($c->bot_state ?? [], ['last_turn_message_id' => $marker])])->save();
                    $burst = app(ReplyScheduler::class)->burst($c->refresh());
                }
            }

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
                // Live eval 2026-10-19: a product answer is an intro line plus a card message — one answer, two bubbles.
                'answers' => self::answers($log->messages),
                'cost_usd' => (float) ($run?->cost_usd ?? 0),
                'model_calls' => count($this->calls),
                'superseded' => $superseded,
                'episode' => ($c->bot_state['episode'] ?? [])['mode'] ?? null,
                'confirm' => FlowState::confirm($c),
                'history' => $this->agentHistory(),
                // Round 4: the open-request decision after a fresh start, the CONTEXT the model read, the handover summary.
                'relates' => ($c->bot_state['episode'] ?? [])['relates']['decision'] ?? null,
                'context' => $this->agentContext(),
                'summary' => $this->handoverSummary($c, (int) $notesBefore),
            ];
            $observed['latency_ms'] = (int) ((hrtime(true) - $started) / 1_000_000);

            return ['observed' => $observed, 'failures' => $this->check((array) ($s['expect'] ?? []), $observed, (array) $s['messages'])];
        } catch (Throwable $e) {
            return ['observed' => ['error' => $e->getMessage()], 'failures' => ['error: '.$e->getMessage()]];
        } finally {
            DB::rollBack();
            app()->instance(OutboundService::class, $previous);
            // Rolled-back ids come back in the next scenario: its burst may be superseded too.
            if ($this->conversation !== null && isset($burst) && $burst->isNotEmpty()) {
                Cache::forget(TurnSupersede::onceKey($this->conversation->id, (int) $burst->first()->id));
            }
            $this->conversation = null;
            app(TurnMetrics::class)->reset();
        }
    }

    /**
     * How many answers she got: a card message right after a text rides on it (ProductBrowser::show sends
     * the intro, then the cards), so «one answer» may be two bubbles.
     *
     * @param  list<array{text:string, cards:array|null}>  $messages
     */
    public static function answers(array $messages): int
    {
        $count = 0;
        $previousWasText = false;

        foreach ($messages as $m) {
            $isCards = ($m['cards'] ?? null) !== null;

            if (! ($isCards && $previousWasText)) {
                $count++;
            }

            $previousWasText = ! $isCards;
        }

        return $count;
    }

    /** The queued turn's clock and id, as RunBotTurn sets them (the hold notice is off in the eval). */
    private function startTurn(Conversation $c, $burst): void
    {
        $metrics = app(TurnMetrics::class);
        $metrics->reset();
        $metrics->startedAtMs = (int) floor(microtime(true) * 1000);
        $metrics->turn = (string) Str::uuid();
        $metrics->lastMessageId = (int) $c->messages()->max('id');
        $metrics->burstFirstId = (int) $burst->first()->id;
        $c->forceFill(['bot_state' => array_merge($c->bot_state ?? [], ['last_turn_message_id' => $burst->last()->id])])->save();
    }

    /** The conversation turns of the last agent call (its CONTEXT block left out). */
    private function agentHistory(): string
    {
        $agent = array_values(array_filter($this->calls, fn ($c) => $c['kind'] === 'agent'));
        $messages = end($agent)['messages'] ?? [];

        return collect($messages)->map(fn ($m) => is_array($m['content'] ?? null)
            ? collect($m['content'])->pluck('text')->reject(fn ($t) => str_starts_with((string) $t, 'CONTEXT'))->implode("\n")
            : (string) ($m['content'] ?? ''))->implode("\n");
    }

    /** The CONTEXT block(s) of the last agent call (what the system told the model about the chat). */
    private function agentContext(): string
    {
        $agent = array_values(array_filter($this->calls, fn ($c) => $c['kind'] === 'agent'));
        $messages = end($agent)['messages'] ?? [];

        return collect($messages)->map(fn ($m) => is_array($m['content'] ?? null)
            ? collect($m['content'])->pluck('text')->filter(fn ($t) => str_starts_with((string) $t, 'CONTEXT'))->implode("\n")
            : '')->filter()->implode("\n");
    }

    /** The handover note written in this turn plus the queue ticket's summary lines, as one text. */
    private function handoverSummary(Conversation $c, int $notesBefore): string
    {
        $note = (string) ConversationNote::query()->where('conversation_id', $c->id)->where('id', '>', $notesBefore)
            ->where('body', 'like', HandoverSummary::NOTE_HEADER.'%')->latest('id')->value('body');
        $entry = $c->queue_entry_id ? QueueEntry::query()->find($c->queue_entry_id) : null;
        $lines = is_array($entry?->bot_summary) ? (array) ($entry->bot_summary['lines'] ?? []) : [];

        return trim($note."\n".implode("\n", array_map('strval', $lines)));
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

        // Round b1: how many messages she got, a superseded draft, the close rule, what the model read.
        if (isset($expect['replies']) && ($o['answers'] ?? count($o['replies'])) !== (int) $expect['replies']) {
            $f[] = 'replies '.($o['answers'] ?? count($o['replies'])).' != '.$expect['replies'];
        }

        if (isset($expect['superseded']) && $o['superseded'] !== (int) $expect['superseded']) {
            $f[] = "superseded {$o['superseded']} != {$expect['superseded']}";
        }

        if (array_key_exists('episode', $expect) && $o['episode'] !== $expect['episode']) {
            $f[] = 'episode '.($o['episode'] ?? 'none').' != '.($expect['episode'] ?? 'none');
        }

        if (array_key_exists('confirm', $expect) && $o['confirm'] !== $expect['confirm']) {
            $f[] = 'confirm '.($o['confirm'] ?? 'none').' != '.($expect['confirm'] ?? 'none');
        }

        if (isset($expect['max_model_calls']) && $o['model_calls'] > (int) $expect['max_model_calls']) {
            $f[] = "model calls {$o['model_calls']} > {$expect['max_model_calls']}";
        }

        foreach ((array) ($expect['model_never_read'] ?? []) as $text) {
            if (str_contains($o['history'], $text)) {
                $f[] = "the model read «{$text}» as this chat";
            }
        }

        foreach ((array) ($expect['model_read'] ?? []) as $text) {
            if (! str_contains($o['history'], $text)) {
                $f[] = "the model never read «{$text}»";
            }
        }

        // Round 4: the open-request decision, the CONTEXT lines, the handover summary.
        if (array_key_exists('relates', $expect) && $o['relates'] !== $expect['relates']) {
            $f[] = 'relates '.($o['relates'] ?? 'none').' != '.($expect['relates'] ?? 'none');
        }

        foreach ((array) ($expect['context_has'] ?? []) as $text) {
            if (! str_contains((string) $o['context'], $text)) {
                $f[] = "the CONTEXT never said «{$text}»";
            }
        }

        foreach ((array) ($expect['summary_has'] ?? []) as $text) {
            if (! str_contains((string) $o['summary'], $text)) {
                $f[] = "the handover summary lacks «{$text}»";
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
                $this->items[$item['title']] = (int) OrderItem::create(['order_id' => $order->id, 'title' => $item['title'], 'sku' => 'EVAL', 'qty' => 1, 'price' => $item['price'], 'discount' => $item['discount'] ?? 0])->id;
            }
        }

        return $c;
    }

    private function setup(Conversation $c, array $setup): void
    {
        if (isset($setup['flow'])) {
            app(FlowEngine::class)->start($c, (string) $setup['flow']);
        }

        // Round b1: what was said before (`history`), an old picture card (`card`), earlier bot turns (`agent_turns`).
        foreach ((array) ($setup['history'] ?? []) as $line) {
            $who = (string) array_key_first((array) $line);
            $who === 'customer' ? $this->storeCustomerMessage($c, (string) $line[$who]) : $this->storeBotMessage($c, (string) $line[$who]);
        }

        if (isset($setup['card'])) {
            $order = Order::where('order_number', $setup['card']['order'])->latest('id')->first();
            $flow = (string) ($setup['card']['flow'] ?? 'return_exchange');
            $step = (string) ($setup['card']['step'] ?? 'return_items');
            $cards = $order?->items->map(fn (OrderItem $i) => ['title' => $i->title, 'subtitle' => '', 'text' => $i->title, 'image_url' => 'https://cdn.shopify.com/s/files/eval.jpg',
                'buttons' => [['type' => 'postback', 'title' => 'رجّع القطعة دي', 'payload' => "step:{$flow}:{$step}:item:{$i->id}"]]])->values()->all() ?? [];
            $this->storeBotMessage($c, 'اختاري القطعة اللي عايزة ترجعيها', ['type' => 'generic', 'cards' => $cards]);
        }

        if (isset($setup['case'])) {
            $order = Order::where('order_number', $setup['case']['order'])->latest('id')->first();
            $pieces = $order?->items->whereIn('title', (array) ($setup['case']['items'] ?? []))
                ->map(fn (OrderItem $i) => ['line_item_id' => $i->id, 'title' => $i->title, 'qty' => 1])->values()->all() ?? [];
            SupportCase::create([
                'conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'platform' => $c->platform->value,
                'type' => $setup['case']['type'], 'status' => 'new', 'priority' => 'medium', 'order_id' => $order?->id,
                'order_number' => '#'.$setup['case']['order'], 'data' => $pieces !== [] ? ['selected_items' => $pieces] : [],
            ]);
        }

        // Earlier agent turns, dated before the close when there is one.
        for ($n = 0; $n < (int) ($setup['agent_turns'] ?? 0); $n++) {
            $run = BotRun::create(['conversation_id' => $c->id, 'trigger_message' => 'eval', 'engine' => 'agent', 'tier' => '1', 'decision' => 'reply', 'input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => 0, 'latency_ms' => 0]);
            BotRun::query()->whereKey($run->id)->update(['created_at' => now()->subMinutes((int) ($setup['closed']['minutes_ago'] ?? 0) + 1)]);
        }

        if (isset($setup['bot_max_turns'])) {
            BotSetting::current()->forceFill(['max_bot_turns' => (int) $setup['bot_max_turns']])->save();
        }

        // A close (`closed: {kind, minutes_ago}`): the marker it leaves, dated back.
        if (isset($setup['closed'])) {
            $marker = ConversationEpisode::marker($c->refresh(), (string) ($setup['closed']['kind'] ?? ConversationEpisode::STAFF));
            $marker['closed_at'] = now()->subMinutes((int) ($setup['closed']['minutes_ago'] ?? 0))->toIso8601String();

            // Round 4: she rated the closed chat — the close is sealed, her next message starts fresh.
            if (! empty($setup['closed']['sealed'])) {
                $marker['sealed'] = true;
            }
            $c->forceFill(['bot_state' => array_merge($c->bot_state ?? [], ['episode' => $marker, 'last_turn_message_id' => (int) $c->messages()->max('id')])])->save();
        }
    }

    private function storeBotMessage(Conversation $c, string $text, ?array $cards = null): void
    {
        $c->messages()->create(['platform' => $c->platform, 'direction' => MessageDirection::Out, 'sender_type' => SenderType::Bot, 'body' => $text,
            'cards' => $cards, 'status' => MessageStatus::Sent, 'is_template' => false]);
    }

    private function storeCustomerMessage(Conversation $c, string $text): void
    {
        $c->messages()->create(['platform' => $c->platform, 'direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => $text,
            'status' => MessageStatus::Received, 'is_template' => false]);
    }

    /** Her messages, stored as the burst of this turn. */
    private function burst(Conversation $c, array $messages)
    {
        return collect($messages)->map(fn ($m) => $c->messages()->create([
            'platform' => $c->platform, 'direction' => MessageDirection::In, 'sender_type' => SenderType::Customer,
            'body' => is_array($m) ? ($m['text'] ?? null) : $m, 'payload' => is_array($m) && isset($m['payload']) ? $this->payload((string) $m['payload']) : null,
            'status' => MessageStatus::Received, 'is_template' => false,
        ]))->values();
    }

    /** `{item:<piece title>}` in a payload becomes that fixture piece's order item id. */
    private function payload(string $payload): string
    {
        return (string) preg_replace_callback('/\{item:([^}]+)\}/u', fn ($m) => (string) ($this->items[$m[1]] ?? 0), $payload);
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
