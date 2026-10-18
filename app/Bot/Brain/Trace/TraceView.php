<?php

namespace App\Bot\Brain\Trace;

use App\Models\BotRule;
use App\Models\BotRun;
use App\Models\Message;
use App\Models\QueueEntry;
use Illuminate\Support\Facades\DB;

/**
 * «ليه البوت رد كده؟» (b2): one bot turn as staff read it — the trace row put together with its
 * bot run (engine, decision, tier, timings, cost) and live facts (the rule's name, who has the chat
 * in the queue now).
 */
class TraceView
{
    /** A bubble with no exact link (sent later by a job) belongs to the latest turn this long before it. */
    private const FALLBACK_SECONDS = 120;

    /** The trace row that produced this bot message, or null (an old message, or not a bot turn). */
    public function traceIdFor(Message $m): ?int
    {
        if ($m->conversation_id === null || $m->created_at === null) {
            return null;
        }

        $rows = DB::table('bot_reply_traces')
            ->where('conversation_id', $m->conversation_id)
            ->whereBetween('created_at', [$m->created_at->copy()->subSeconds(self::FALLBACK_SECONDS), $m->created_at->copy()->addSeconds(self::FALLBACK_SECONDS)])
            ->orderByDesc('id')
            ->get(['id', 'message_ids', 'created_at']);

        foreach ($rows as $row) {
            if (in_array($m->id, (array) json_decode((string) $row->message_ids, true), true)) {
                return (int) $row->id;
            }
        }

        // Not listed: the latest turn written before it (a delayed send of that turn).
        $earlier = $rows->first(fn ($row) => $row->created_at <= $m->created_at->copy()->addSeconds(5)->toDateTimeString());

        return $earlier !== null ? (int) $earlier->id : null;
    }

    /** @return array<string, mixed>|null */
    public function show(int $traceId): ?array
    {
        $row = DB::table('bot_reply_traces')->find($traceId);
        $run = $row !== null ? BotRun::query()->find($row->bot_run_id) : null;

        if ($row === null || $run === null) {
            return null;
        }

        return self::compose($run, (array) json_decode((string) $row->trace, true), (int) $row->id, (array) json_decode((string) $row->message_ids, true));
    }

    /**
     * @param  array<string, mixed>  $trace
     * @param  list<int>  $messageIds
     * @return array<string, mixed>
     */
    public static function compose(BotRun $run, array $trace, ?int $traceId = null, array $messageIds = []): array
    {
        [$kinds, $reason] = self::intentParts($run);
        $calls = (array) ($trace['calls'] ?? []);
        $handover = $trace['handover'] ?? null;

        if (is_array($handover) && ($handover['queue_entry_id'] ?? null) !== null) {
            $entry = QueueEntry::query()->with('assignee:id,name')->find($handover['queue_entry_id'], ['id', 'status', 'assigned_user_id']);
            $handover['queue_status'] = $entry?->status;
            $handover['assignee'] = $entry?->assignee?->name;
        }

        return [
            'id' => $traceId,
            'bot_run_id' => $run->id,
            'conversation_id' => $run->conversation_id,
            'at' => $run->created_at?->toIso8601String(),
            'trigger' => $run->trigger_message,
            'router' => $trace['router'] ?? null,
            'engine' => $run->engine,
            'decision' => $run->decision,
            'layer' => self::layer($run),
            'tier' => $run->tier,
            'kinds' => $kinds,
            'reason' => $reason,
            'tools_used' => self::toolsFromIntent($run),
            'rule' => $run->rule_id !== null ? ['id' => $run->rule_id, 'name' => BotRule::query()->whereKey($run->rule_id)->value('name')] : null,
            'plan' => $trace['plan'] ?? null,
            'calls' => $calls,
            'tools' => (array) ($trace['tools'] ?? []),
            'guards' => (array) ($trace['guards'] ?? []),
            'handover' => $handover,
            'message_ids' => array_values($messageIds),
            'model' => $run->model,
            'cost_usd' => round((float) $run->cost_usd, 6),
            'tokens' => ['in' => (int) $run->input_tokens, 'out' => (int) $run->output_tokens, 'cache_read' => (int) $run->cache_read_tokens],
            'timing' => [
                'total_ms' => (int) $run->latency_ms,
                'burst_wait_ms' => $run->burst_wait_ms,
                'planner_ms' => $run->planner_ms,
                'llm_ms' => $run->llm_ms,
                'tools_ms' => $run->tools_ms,
                'rounds' => $run->rounds,
            ],
        ];
    }

    /**
     * Which layer answered, in the words staff use: an instant script, an owner rule, a guided
     * flow, the AI agent, the safe fallback sentence, or a handover to the team.
     */
    public static function layer(BotRun $run): string
    {
        if (in_array($run->decision, ['handover'], true) || $run->tier === 'handover') {
            return 'handover';
        }

        if ($run->decision === 'safe_fallback') {
            return 'fallback';
        }

        return match ($run->engine) {
            'rule' => 'rule',
            'flow', 'flow_engine' => 'flow',
            'agent', 'ai' => 'agent',
            'hybrid' => match ((string) $run->tier) {
                '0' => 'script',
                'flow' => 'flow',
                default => 'agent',
            },
            'keyword', 'limit', 'signal' => 'handover',
            default => 'script',
        };
    }

    /** @return array{0: list<string>, 1: ?string} the planner's kinds and the router's reason, from bot_runs.intent */
    private static function intentParts(BotRun $run): array
    {
        if ($run->engine !== 'hybrid' || $run->intent === null) {
            return [[], null];
        }

        $parts = explode('|', (string) $run->intent);
        $kinds = str_starts_with($parts[0], 'faq:') ? [] : array_values(array_filter(explode(',', $parts[0])));
        $reason = isset($parts[1]) && ! str_starts_with($parts[1], 'tools:') ? $parts[1] : null;

        return [$kinds, $reason];
    }

    /** @return list<string> */
    private static function toolsFromIntent(BotRun $run): array
    {
        if ($run->intent === null || ! preg_match('/(?:^|\|)tools:([^|]+)/', (string) $run->intent, $m)) {
            return in_array($run->engine, ['agent'], true) && $run->intent !== null && ! str_contains((string) $run->intent, '|')
                ? array_values(array_filter(explode(',', (string) $run->intent)))
                : [];
        }

        return array_values(array_filter(explode(',', $m[1])));
    }
}
