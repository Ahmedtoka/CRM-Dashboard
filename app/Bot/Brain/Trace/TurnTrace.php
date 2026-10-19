<?php

namespace App\Bot\Brain\Trace;

use App\Analytics\ActivityLogger;
use App\Bot\Ai\ModelPricing;
use App\Bot\Router\HybridRouter;
use App\Bot\Router\TurnMetrics;
use App\Enums\Handler;
use App\Models\ActivityLog;
use App\Models\BotRun;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\QueueEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * «ليه البوت رد كده؟» (b2, 2026-10-19): what one bot turn did, collected while it runs and written
 * next to its bot run (bot_reply_traces). A scoped singleton like TurnMetrics, fed from the outside
 * so the engine code barely changes: every Messages API response (the planner's tasks, each model
 * call, the agent's tool calls and their results), the reply guards, the bot messages created, and
 * finally the bot run itself (BotRun::save). What the bot run row already holds (engine, decision,
 * tier, cost, timings) is not copied; TraceView puts the two together.
 *
 * Never allowed to break a bot turn: every entry point swallows its own errors.
 */
class TurnTrace
{
    private const TEXT_LIMIT = 280;

    /** The turn this trace belongs to (TurnMetrics' start); a different one resets it. */
    private ?int $turnKey = null;

    /** @var list<array<string, mixed>> */
    private array $calls = [];

    /** @var array<string, array<string, mixed>> keyed by tool_use id, in call order */
    private array $tools = [];

    /** @var list<string> 'pass' or the guard that refused */
    private array $guards = [];

    /** @var array<string, mixed>|null */
    private ?array $plan = null;

    /** @var list<int> bot messages created before the run was written */
    private array $messageIds = [];

    /** The trace row written for the last run of this turn (later bot messages join it). */
    private ?int $lastTraceId = null;

    /** @var array<string, mixed>|null the payload written last (the test console reads it before its rollback) */
    public ?array $lastPayload = null;

    public function reset(): void
    {
        $this->turnKey = null;
        $this->calls = $this->tools = $this->guards = $this->messageIds = [];
        $this->plan = null;
        $this->lastTraceId = null;
    }

    /**
     * One Messages API exchange.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $response
     */
    public function recordModelCall(array $body, array $response, int $ms): void
    {
        $this->guard(function () use ($body, $response, $ms) {
            $this->sync();
            $model = (string) ($response['model'] ?? $body['model'] ?? '');
            $usage = (array) ($response['usage'] ?? []);
            $structured = isset($body['output_config']['format']);
            $phase = isset($body['tools']) ? 'agent' : ($this->isPlanner($body) ? 'planner' : ($structured ? 'understanding' : 'reply'));

            // The results of the previous round's tools travel in this request's last user turn.
            if ($phase === 'agent') {
                $this->collectToolResults($body);
            }

            $this->calls[] = [
                'phase' => $phase,
                'model' => $model,
                'in' => (int) ($usage['input_tokens'] ?? 0),
                'out' => (int) ($usage['output_tokens'] ?? 0),
                'cache_read' => (int) ($usage['cache_read_input_tokens'] ?? 0),
                'cache_write' => (int) ($usage['cache_creation_input_tokens'] ?? 0),
                'cost' => ModelPricing::ofUsage($model, $usage),
                'ms' => $ms,
                'stop' => $response['stop_reason'] ?? null,
            ];

            foreach ((array) ($response['content'] ?? []) as $block) {
                if (($block['type'] ?? null) === 'tool_use') {
                    $this->tools[(string) ($block['id'] ?? count($this->tools))] = [
                        'name' => (string) ($block['name'] ?? ''),
                        'input' => self::short(json_encode($block['input'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
                        'output' => null,
                    ];
                }
            }

            if ($phase === 'planner') {
                $this->plan = $this->planFrom($response);
            }
        });
    }

    /** The reply guards' verdict on the agent's reply: null passed, else the guard that refused. */
    public function recordGuard(?string $failure): void
    {
        $this->guard(function () use ($failure) {
            $this->sync();
            $this->guards[] = $failure ?? 'pass';
        });
    }

    public function recordMessage(Message $m): void
    {
        $this->guard(function () use ($m) {
            $this->sync();

            if ($this->lastTraceId !== null) {
                $row = DB::table('bot_reply_traces')->where('id', $this->lastTraceId)->first(['message_ids']);
                $ids = array_values(array_unique([...((array) json_decode((string) ($row->message_ids ?? '[]'), true)), $m->id]));
                DB::table('bot_reply_traces')->where('id', $this->lastTraceId)->update(['message_ids' => json_encode($ids)]);

                return;
            }

            $this->messageIds[] = $m->id;
        });
    }

    /** Called once a bot run of a conversation was written: the trace row, with everything collected so far. */
    public function persist(BotRun $run): void
    {
        if ($run->conversation_id === null) {
            return;
        }

        $this->guard(function () use ($run) {
            $this->sync();
            $payload = [
                'v' => 1,
                'router' => HybridRouter::mode(),
                'plan' => $this->plan,
                'calls' => $this->calls,
                'tools' => array_values($this->tools),
                'guards' => $this->guards,
                'handover' => $this->handover($run),
            ];

            $this->lastTraceId = (int) DB::table('bot_reply_traces')->insertGetId([
                'bot_run_id' => $run->id,
                'conversation_id' => $run->conversation_id,
                'message_ids' => json_encode(array_values(array_unique($this->messageIds))),
                'trace' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->lastPayload = $payload + ['message_ids' => $this->messageIds, 'trace_id' => $this->lastTraceId];

            // A second run in the same turn starts its own list (its messages are not this one's).
            $this->calls = $this->tools = $this->guards = $this->messageIds = [];
            $this->plan = null;
        });
    }

    /** @return array<string, mixed>|null where the chat went if this run handed it to the team */
    private function handover(BotRun $run): ?array
    {
        $c = Conversation::query()->find($run->conversation_id, ['id', 'handler', 'handover_at', 'handover_category', 'priority_level', 'queue']);

        if ($c === null || $c->handler !== Handler::Human || $c->handover_at === null || $c->handover_at->lt(now()->subMinutes(2))) {
            return null;
        }

        $log = ActivityLog::query()->where('conversation_id', $c->id)->where('action', ActivityLogger::CONVERSATION_HANDOVER)->latest('id')->first(['meta']);
        $entry = QueueEntry::query()->where('conversation_id', $c->id)->latest('id')->first(['id']);

        return [
            'reason' => $log?->meta['reason'] ?? null,
            'category' => $c->handover_category,
            'priority' => $c->priority_level,
            'queue' => $c->queue,
            'queue_entry_id' => $entry?->id,
        ];
    }

    private function collectToolResults(array $body): void
    {
        $last = end($body['messages']);

        if (! is_array($last) || ($last['role'] ?? null) !== 'user' || ! is_array($last['content'] ?? null)) {
            return;
        }

        foreach ($last['content'] as $block) {
            $id = (string) ($block['tool_use_id'] ?? '');

            if (($block['type'] ?? null) === 'tool_result' && isset($this->tools[$id]) && $this->tools[$id]['output'] === null) {
                $content = $block['content'] ?? '';
                $this->tools[$id]['output'] = self::short(is_string($content) ? $content : (json_encode($content, JSON_UNESCAPED_UNICODE) ?: ''));
            }
        }
    }

    private function isPlanner(array $body): bool
    {
        return isset($body['output_config']['format']['schema']['properties']['tasks']);
    }

    /** @return array<string, mixed>|null */
    private function planFrom(array $response): ?array
    {
        $text = collect((array) ($response['content'] ?? []))->where('type', 'text')->pluck('text')->implode('');
        $json = json_decode($text, true);

        if (! is_array($json) || ! is_array($json['tasks'] ?? null)) {
            return null;
        }

        return [
            'tasks' => array_map(fn ($t) => array_filter([
                'kind' => $t['kind'] ?? null,
                'order_refs' => $t['order_refs'] ?? [],
                'items' => array_values(array_filter(array_map(fn ($i) => $i['text'] ?? null, (array) ($t['items'] ?? [])))),
                'product_query' => $t['product_query'] ?? null,
                'question' => isset($t['question']) ? self::short((string) $t['question']) : null,
                'confidence' => $t['confidence'] ?? null,
            ], fn ($v) => $v !== null && $v !== []), array_slice($json['tasks'], 0, 8)),
            'sentiment' => $json['sentiment'] ?? null,
            'wants_human' => (bool) ($json['wants_human'] ?? false),
            'policy_edge' => (bool) ($json['policy_edge'] ?? false),
            'clarify' => $json['clarify'] ?? null,
        ];
    }

    /** A new turn (TurnMetrics restarted) never inherits the previous turn's leftovers. */
    private function sync(): void
    {
        $key = app(TurnMetrics::class)->startedAtMs;

        if ($key !== null && $key !== $this->turnKey) {
            $this->reset();
            $this->turnKey = $key;
        }
    }

    private function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function short(string $text): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), self::TEXT_LIMIT, '…');
    }
}
