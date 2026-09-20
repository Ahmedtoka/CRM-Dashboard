<?php

namespace App\Analytics;

use App\Enums\ActorType;
use App\Enums\CommentStatus;
use App\Enums\ConversationPriority;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ParticipantRole;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\ShipmentStatus;
use App\Models\ActivityLog;
use App\Models\AnalyticsDaily;
use App\Models\BotFlow;
use App\Models\BotRule;
use App\Models\BotRun;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\Order;
use App\Models\ShipmentEvent;
use App\Models\SupportCase;
use App\Models\User;
use App\Models\UserSession;
use App\TestLinks\TestScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Moderator, team and bot metrics. Ranges of up to 31 days are computed live from source
 * tables; longer user ranges read analytics_daily for full past Cairo days (see crm:rollup)
 * and compute only the partial edge day and today live. Team and bot metrics are always
 * live but use aggregate queries only.
 */
class MetricsService
{
    public const TZ = 'Africa/Cairo';

    public const LIVE_MAX_DAYS = 31;

    /** Integer keys summed from analytics_daily rows plus the live edges. */
    private const ROLLUP_SUM_KEYS = ['messages_sent', 'conversations_handled', 'first_responses', 'follow_ups', 'resolved', 'comments_handled', 'orders_count', 'online_minutes'];

    private const EXCLUDED_ORDER_STATUSES = [OrderStatus::Cancelled->value, OrderStatus::Failed->value];

    /** Shopify financial statuses whose order value counts as collected (spec §6.3). */
    private const REALIZED_FINANCIAL_STATUSES = ['paid', 'partially_refunded'];

    public function userMetrics(User $u, CarbonInterface $from, CarbonInterface $to, ?Platform $platform = null): array
    {
        [$from, $to] = $this->normalize($from, $to);

        $raw = $from->diffInDays($to) <= self::LIVE_MAX_DAYS
            ? $this->liveUser($u->id, $from, $to, $platform)
            : $this->longRangeUser($u->id, $from, $to, $platform);

        return $this->publicUser($raw);
    }

    public function teamMetrics(CarbonInterface $from, CarbonInterface $to, ?Platform $platform = null): array
    {
        [$from, $to] = $this->normalize($from, $to);
        $p = $platform?->value;

        $messageGroups = Message::query()
            ->where('is_test', false)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('platform, direction, sender_type, count(*) as n')
            ->groupBy('platform', 'direction', 'sender_type')
            ->toBase()
            ->get();

        $count = fn (string $direction, ?string $sender, ?string $plat) => (int) $messageGroups
            ->filter(fn ($r) => $r->direction === $direction
                && ($sender === null || $r->sender_type === $sender)
                && ($plat === null || $r->platform === $plat))
            ->sum('n');

        $commentGroups = Comment::query()
            ->join('posts', 'posts.id', '=', 'comments.post_id')
            ->whereBetween('comments.created_at', [$from, $to])
            ->selectRaw('posts.platform as platform, comments.status as status, count(*) as n')
            ->groupBy('posts.platform', 'comments.status')
            ->toBase()
            ->get();

        $orderGroups = Order::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereNotIn('status', self::EXCLUDED_ORDER_STATUSES)
            ->selectRaw('platform, source, count(*) as n, sum(total) as total')
            ->groupBy('platform', 'source')
            ->toBase()
            ->get();

        $forPlatform = fn (Collection $rows, ?string $plat) => $plat === null ? $rows : $rows->where('platform', $plat);

        $comments = $forPlatform($commentGroups, $p);
        $commentsByStatus = [];
        foreach (CommentStatus::cases() as $status) {
            $commentsByStatus[$status->value] = (int) $comments->where('status', $status->value)->sum('n');
        }

        $orders = $forPlatform($orderGroups, $p);

        // Spam/low-priority conversations never need a human and never count as
        // "waiting", matching ConversationQuery's inbox filters (spec §11.1).
        $open = fn () => Conversation::query()
            ->where('is_test', false)
            ->where('status', '!=', ConversationStatus::Resolved->value)
            ->whereNotIn('priority', [ConversationPriority::Low->value, ConversationPriority::Spam->value])
            ->when($p, fn ($q) => $q->where('platform', $p));

        $waitingNow = $open()
            ->whereNotNull('last_customer_message_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('messages')
                ->where('messages.is_test', false)
                ->whereColumn('messages.conversation_id', 'conversations.id')
                ->where('messages.direction', MessageDirection::Out->value)
                ->whereIn('messages.sender_type', [SenderType::User->value, SenderType::Bot->value])
                ->whereColumn('messages.created_at', '>', 'conversations.last_customer_message_at'))
            ->count();

        $firstResponseSeconds = $this->logs([ActivityLogger::CONVERSATION_FIRST_RESPONSE], $from, $to, $platform)
            ->map(fn (ActivityLog $l) => $l->meta['seconds'] ?? null)
            ->filter(fn ($s) => $s !== null);

        $byPlatform = [];
        foreach (Platform::cases() as $case) {
            $v = $case->value;
            $byPlatform[$v] = [
                'inbound' => $count(MessageDirection::In->value, null, $v),
                'outbound' => $count(MessageDirection::Out->value, SenderType::User->value, $v),
                'comments' => (int) $commentGroups->where('platform', $v)->sum('n'),
                'orders_count' => (int) $orderGroups->where('platform', $v)->sum('n'),
                'orders_total' => round((float) $orderGroups->where('platform', $v)->sum('total'), 2),
            ];
        }

        $byHour = array_fill(0, 24, 0);
        $inbound = Message::query()
            ->where('is_test', false)
            ->whereBetween('created_at', [$from, $to])
            ->where('direction', MessageDirection::In->value)
            ->when($p, fn ($q) => $q->where('platform', $p));
        foreach ($this->countsPerUtcHour($inbound) as [$local, $n]) {
            $byHour[$local->hour] += $n;
        }

        return [
            'inbound_messages' => $count(MessageDirection::In->value, null, $p),
            'outbound_messages' => $count(MessageDirection::Out->value, SenderType::User->value, $p),
            'bot_messages' => $count(MessageDirection::Out->value, SenderType::Bot->value, $p),
            'conversations_new' => Conversation::query()->where('is_test', false)->whereBetween('created_at', [$from, $to])->when($p, fn ($q) => $q->where('platform', $p))->count(),
            'conversations_resolved' => Conversation::query()->where('is_test', false)->whereBetween('resolved_at', [$from, $to])->when($p, fn ($q) => $q->where('platform', $p))->count(),
            'waiting_now' => $waitingNow,
            'needs_human_now' => $open()->where('needs_human', true)->count(),
            'avg_first_response_sec' => $this->avg($firstResponseSeconds->all()),
            'comments_total' => (int) $comments->sum('n'),
            'comments_by_status' => $commentsByStatus,
            'orders_count' => (int) $orders->sum('n'),
            'orders_total' => round((float) $orders->sum('total'), 2),
            'by_platform' => $byPlatform,
            'by_hour' => $byHour,
        ] + $this->outcomeKeys(
            (int) $orders->sum('n'),
            (float) $orders->sum('total'),
            ['chat' => (int) $orders->where('source', 'chat')->sum('n'), 'store' => (int) $orders->where('source', 'store')->sum('n')],
            $this->orderOutcomes($from, $to, null, $p),
        );
    }

    public function botMetrics(CarbonInterface $from, CarbonInterface $to, ?Platform $platform = null): array
    {
        [$from, $to] = $this->normalize($from, $to);
        $p = $platform?->value;

        $botMessages = fn () => Message::query()
            ->where('is_test', false)
            ->whereBetween('created_at', [$from, $to])
            ->where('direction', MessageDirection::Out->value)
            ->where('sender_type', SenderType::Bot->value)
            ->when($p, fn ($q) => $q->where('platform', $p));

        $runs = fn () => TestScope::excludeConversations(BotRun::query())
            ->whereBetween('created_at', [$from, $to])
            ->when($p, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereHas('conversation', fn ($c) => $c->where('platform', $p))
                ->orWhereHas('comment.post', fn ($c) => $c->where('platform', $p))));

        // Distinct conversations with a bot message or a bot run: UNION de-duplicates in SQL.
        $touchedIds = $botMessages()->whereNotNull('conversation_id')->select('conversation_id')->toBase()
            ->union($runs()->whereNotNull('conversation_id')->select('conversation_id')->toBase());
        $touched = DB::query()->fromSub($touchedIds, 'touched')->count();

        $autoResolved = Conversation::query()
            ->where('is_test', false)
            ->whereBetween('resolved_at', [$from, $to])
            ->when($p, fn ($q) => $q->where('platform', $p))
            ->whereDoesntHave('participants', fn ($q) => $q->whereNotNull('user_id'))
            ->count();

        $handoverLogs = $this->logs([ActivityLogger::CONVERSATION_HANDOVER], $from, $to, $platform);
        $handovers = $handoverLogs->count();

        $reasons = $handoverLogs
            ->countBy(fn (ActivityLog $l) => (string) ($l->meta['reason'] ?? 'unknown'))
            ->sortDesc()
            ->all();

        $hitsByRule = $runs()
            ->whereNotNull('rule_id')
            ->selectRaw('rule_id, count(*) as hits')
            ->groupBy('rule_id')
            ->toBase()
            ->get();
        $ruleNames = BotRule::whereIn('id', $hitsByRule->pluck('rule_id'))->pluck('name', 'id');
        $ruleHits = $hitsByRule
            ->map(fn ($r) => ['rule_id' => (int) $r->rule_id, 'name' => $ruleNames[(int) $r->rule_id] ?? null, 'hits' => (int) $r->hits])
            ->sortByDesc('hits')
            ->values()
            ->all();

        $botComments = $this->logs(
            [ActivityLogger::COMMENT_REPLIED, ActivityLogger::COMMENT_HIDDEN, ActivityLogger::COMMENT_PRIVATE_REPLY],
            $from, $to, $platform,
            fn ($q) => $q->where('actor_type', ActorType::Bot->value),
        )->countBy('action');

        return [
            'messages_sent' => $botMessages()->count(),
            'conversations_touched' => $touched,
            'auto_resolved' => $autoResolved,
            'handovers' => $handovers,
            'handover_rate' => $touched > 0 ? round($handovers / $touched, 2) : 0.0,
            'handover_reasons' => $reasons,
            'rule_hits' => $ruleHits,
            'ai_runs' => $runs()->where('engine', 'ai')->count(),
            'ai_cost_usd' => round((float) $runs()->sum('cost_usd'), 4),
            'comments_replied' => (int) ($botComments[ActivityLogger::COMMENT_REPLIED] ?? 0),
            'comments_hidden' => (int) ($botComments[ActivityLogger::COMMENT_HIDDEN] ?? 0),
            'private_replies' => (int) ($botComments[ActivityLogger::COMMENT_PRIVATE_REPLY] ?? 0),
            'flows' => $this->flowUsage($from, $to, $platform),
        ];
    }

    /**
     * Per guided flow, for the period (Reports → Bot "الفلوهات" table). Nothing logs a
     * flow's start or end explicitly, so this replays the conversation's bot_runs in order:
     * a `flow_engine` run by ConversationRouter stores the flow still active AFTER the
     * turn in `intent` (null once it cleared).
     *
     *  - started:   distinct conversations with such a run for the flow;
     *  - finished:  the flow was active and a later non-handover router run shows it
     *               cleared (its `end` step) — or, for a menu flow, moved on to another flow;
     *  - handovers: the flow was active when a run handed the conversation over;
     *  - cases:     support cases of the flow's `record_case` (or `status` → delivery_followup) types opened in the period
     *               in a conversation that started the flow.
     *
     * A flow that starts and ends inside one turn leaves no trace and is not counted.
     *
     * @return list<array{key:string, title:string, is_active:bool, started:int, finished:int, handovers:int, cases:int, records_cases:bool}>
     */
    private function flowUsage(CarbonImmutable $from, CarbonImmutable $to, ?Platform $platform): array
    {
        $flows = BotFlow::query()->orderBy('id')->get(['id', 'key', 'title_ar', 'is_active', 'definition']);
        $keys = $flows->pluck('key')->all();

        $runs = TestScope::excludeConversations(BotRun::query(), 'bot_runs.conversation_id')
            ->whereBetween('bot_runs.created_at', [$from, $to])
            ->whereNotNull('bot_runs.conversation_id')
            ->when($platform, fn (Builder $q) => $q->whereHas('conversation', fn ($c) => $c->where('platform', $platform->value)))
            ->orderBy('bot_runs.conversation_id')
            ->orderBy('bot_runs.id')
            ->toBase()
            ->get(['conversation_id', 'engine', 'intent', 'decision']);

        $menuFlows = $flows->filter(fn (BotFlow $f) => (($f->definition['steps'][$f->definition['start'] ?? ''] ?? [])['type'] ?? null) === 'menu')->pluck('key')->all();
        $started = $finished = $handovers = array_fill_keys($keys, []);

        foreach ($runs->groupBy('conversation_id') as $conversationId => $conversationRuns) {
            $active = null;

            foreach ($conversationRuns as $run) {
                $isHandover = in_array($run->decision, ['handover', 'reply_and_handover'], true);
                $isRouterTurn = $run->engine === 'flow_engine' && in_array($run->decision, ['flow', 'button', 'menu', 'handover'], true);

                if ($isHandover && $active !== null) {
                    $handovers[$active][$conversationId] = true;
                    $active = null;

                    continue;
                }

                if (! $isRouterTurn) {
                    continue; // agent turns (answers mid-flow) keep the flow active
                }

                $now = in_array($run->intent, $keys, true) ? $run->intent : null;

                if ($now !== null) {
                    $started[$now][$conversationId] = true;
                }

                if ($active !== null && $now !== $active && ($now === null || in_array($active, $menuFlows, true))) {
                    $finished[$active][$conversationId] = true;
                }

                $active = $now;
            }
        }

        // record_case steps open a case of their `case_type`; a `status` step's flow counts its
        // delivery_followup cases (the step opened them itself before 2026-09-19, now its
        // «الأوردر اتأخر» button leads to a record_case step).
        $caseTypes = $flows->mapWithKeys(fn (BotFlow $f) => [$f->key => collect($f->definition['steps'] ?? [])
            ->map(fn ($step) => match ($step['type'] ?? null) {
                'record_case' => $step['case_type'] ?? null,
                'status' => 'delivery_followup',
                default => null,
            })
            ->filter()->unique()->values()->all()]);

        $cases = TestScope::excludeConversations(SupportCase::query())
            ->whereBetween('created_at', [$from, $to])
            ->when($platform, fn ($q) => $q->where('platform', $platform->value))
            ->toBase()
            ->get(['conversation_id', 'type']);

        return $flows
            ->map(fn (BotFlow $f) => [
                'key' => $f->key,
                'title' => (string) $f->title_ar,
                'is_active' => (bool) $f->is_active,
                'started' => count($started[$f->key]),
                'finished' => count($finished[$f->key]),
                'handovers' => count($handovers[$f->key]),
                'cases' => $cases
                    ->filter(fn ($c) => in_array($c->type, $caseTypes[$f->key], true) && isset($started[$f->key][$c->conversation_id]))
                    ->count(),
                'records_cases' => $caseTypes[$f->key] !== [],
            ])
            ->sortByDesc('started')
            ->values()
            ->all();
    }

    /**
     * Team leaderboard: the userMetrics() keys plus `user`, for every active user at once.
     * Counts come from grouped queries across all users (a fixed number of queries, not
     * one set per user). Response-time averages read analytics_daily for past Cairo days
     * that were rolled up and scan messages only for the remaining windows (normally today).
     *
     * @return array<int, array>
     */
    public function leaderboard(CarbonInterface $from, CarbonInterface $to): array
    {
        [$from, $to] = $this->normalize($from, $to);

        $users = User::query()->where('is_active', true)->orderBy('id')->get(['id', 'name', 'color']);

        if ($users->isEmpty()) {
            return [];
        }

        $ids = $users->pluck('id')->all();
        $byUser = fn (Collection $rows) => $rows->groupBy(fn ($r) => (int) $r->user_id);

        $messages = $byUser($this->humanOutbound($from, $to, null, null)
            ->whereIn('user_id', $ids)
            ->selectRaw('user_id, platform, count(*) as n')
            ->groupBy('user_id', 'platform')
            ->toBase()
            ->get());

        $handled = $this->humanOutbound($from, $to, null, null)
            ->whereIn('user_id', $ids)
            ->selectRaw('user_id, count(distinct conversation_id) as n')
            ->groupBy('user_id')
            ->toBase()
            ->pluck('n', 'user_id');

        $roles = $byUser(TestScope::excludeConversations(ConversationParticipant::query())
            ->whereIn('user_id', $ids)
            ->whereBetween('first_message_at', [$from, $to])
            ->selectRaw('user_id, role, count(*) as n')
            ->groupBy('user_id', 'role')
            ->toBase()
            ->get());

        $actions = $byUser(TestScope::excludeConversations(ActivityLog::query())
            ->whereIn('user_id', $ids)
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('action', [ActivityLogger::CONVERSATION_RESOLVED, ActivityLogger::COMMENT_REPLIED, ActivityLogger::COMMENT_HIDDEN, ActivityLogger::COMMENT_PRIVATE_REPLY])
            ->selectRaw('user_id, action, count(*) as n')
            ->groupBy('user_id', 'action')
            ->toBase()
            ->get());

        $firstResponses = TestScope::excludeConversations(ActivityLog::query())
            ->whereIn('user_id', $ids)
            ->where('action', ActivityLogger::CONVERSATION_FIRST_RESPONSE)
            ->whereBetween('created_at', [$from, $to])
            ->get(['id', 'user_id', 'meta'])
            ->groupBy('user_id')
            ->map(fn (Collection $logs) => $logs->map(fn (ActivityLog $l) => $l->meta['seconds'] ?? null)->filter(fn ($s) => $s !== null)->values()->all());

        $orders = $byUser(Order::query()
            ->whereIn('created_by_id', $ids)
            ->whereBetween('created_at', [$from, $to])
            ->whereNotIn('status', self::EXCLUDED_ORDER_STATUSES)
            ->selectRaw('created_by_id as user_id, platform, type, source, count(*) as n, sum(total) as total, sum(case when paid_at is not null then 1 else 0 end) as paid')
            ->groupBy('created_by_id', 'platform', 'type', 'source')
            ->toBase()
            ->get());

        $online = $this->onlineMinutesByUser($ids, $from, $to);
        $responses = $this->responseTimesByUser($ids, $from, $to);
        $outcomes = $this->orderOutcomesByUser($from, $to, $ids);

        $rows = $users->map(function (User $u) use ($messages, $handled, $roles, $actions, $firstResponses, $orders, $online, $responses, $outcomes) {
            $id = $u->id;
            $m = $messages->get($id, collect());
            $r = $roles->get($id, collect());
            $a = $actions->get($id, collect());
            $o = $orders->get($id, collect());
            $sum = fn (Collection $rows, string $column, string $value, string $field = 'n') => $rows->where($column, $value)->sum(fn ($row) => (float) $row->{$field});
            $fr = $firstResponses->get($id, []);
            [$rtSum, $rtN] = $responses[$id] ?? [0, 0];

            $byPlatform = [];
            foreach (Platform::cases() as $case) {
                $byPlatform[$case->value] = [
                    'messages_sent' => (int) $sum($m, 'platform', $case->value),
                    'orders_count' => (int) $sum($o, 'platform', $case->value),
                ];
            }

            $commentsHandled = (int) ($sum($a, 'action', ActivityLogger::COMMENT_REPLIED) + $sum($a, 'action', ActivityLogger::COMMENT_HIDDEN) + $sum($a, 'action', ActivityLogger::COMMENT_PRIVATE_REPLY));

            return [
                'messages_sent' => (int) $m->sum(fn ($row) => (int) $row->n),
                'conversations_handled' => (int) ($handled[$id] ?? 0),
                'first_responses' => (int) $sum($r, 'role', ParticipantRole::First->value),
                'continued' => (int) $sum($r, 'role', ParticipantRole::Continued->value),
                'follow_ups' => (int) $sum($r, 'role', ParticipantRole::FollowUp->value),
                'resolved' => (int) $sum($a, 'action', ActivityLogger::CONVERSATION_RESOLVED),
                'avg_first_response_sec' => $this->avg($fr),
                'avg_response_sec' => $rtN > 0 ? (int) round($rtSum / $rtN) : 0,
                'comments_handled' => $commentsHandled,
                'private_replies' => (int) $sum($a, 'action', ActivityLogger::COMMENT_PRIVATE_REPLY),
                'orders_count' => (int) $o->sum(fn ($row) => (int) $row->n),
                'orders_total' => round((float) $o->sum(fn ($row) => (float) $row->total), 2),
                'cod_count' => (int) $sum($o, 'type', OrderType::Cod->value),
                'payment_link_count' => (int) $sum($o, 'type', OrderType::PaymentLink->value),
                'payment_link_paid' => (int) $sum($o, 'type', OrderType::PaymentLink->value, 'paid'),
                'online_minutes' => (int) ($online[$id] ?? 0),
                'by_platform' => $byPlatform,
                ...$this->outcomeKeys(
                    (int) $o->sum(fn ($row) => (int) $row->n),
                    (float) $o->sum(fn ($row) => (float) $row->total),
                    ['chat' => (int) $sum($o, 'source', 'chat'), 'store' => (int) $sum($o, 'source', 'store')],
                    $outcomes[$id],
                ),
                'user' => ['id' => $u->id, 'name' => $u->name, 'color' => $u->color],
            ];
        })->all();

        usort($rows, fn (array $a, array $b) => $b['messages_sent'] <=> $a['messages_sent']);

        return $rows;
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int> user id => online minutes (same clipping as onlineMinutes())
     */
    private function onlineMinutesByUser(array $ids, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $seconds = [];

        UserSession::query()
            ->whereIn('user_id', $ids)
            ->where('started_at', '<=', $to)
            ->whereRaw('coalesce(ended_at, last_heartbeat_at) >= ?', [$from->toDateTimeString()])
            ->get(['id', 'user_id', 'started_at', 'ended_at', 'last_heartbeat_at'])
            ->each(function (UserSession $s) use (&$seconds, $from, $to) {
                $end = $s->ended_at ?? $s->last_heartbeat_at;
                if ($s->started_at === null || $end === null) {
                    return;
                }
                $start = $s->started_at->greaterThan($from) ? CarbonImmutable::instance($s->started_at) : $from;
                $end = $end->lessThan($to) ? CarbonImmutable::instance($end) : $to;

                $seconds[(int) $s->user_id] = ($seconds[(int) $s->user_id] ?? 0) + ($end->greaterThan($start) ? (int) $start->diffInSeconds($end) : 0);
            });

        return array_map(fn (int $s) => intdiv($s, 60), $seconds);
    }

    /**
     * Response-time sums per user: rolled-up past Cairo days contribute
     * avg_response_sec × messages_sent; every stretch of the range without a rollup
     * (normally just today) is computed from messages in one bounded scan.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{0: float, 1: int}> user id => [sum of seconds, samples]
     */
    private function responseTimesByUser(array $ids, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $totals = [];
        $add = function (int $userId, float $sum, int $n) use (&$totals) {
            $totals[$userId] ??= [0.0, 0];
            $totals[$userId][0] += $sum;
            $totals[$userId][1] += $n;
        };

        $fromLocal = $from->setTimezone(self::TZ);
        $firstFull = $fromLocal->startOfDay();
        if (! $firstFull->equalTo($fromLocal)) {
            $firstFull = $firstFull->addDay();
        }

        $todayStart = CarbonImmutable::now(self::TZ)->startOfDay();
        $afterLast = $to->setTimezone(self::TZ)->addSecond()->startOfDay();
        if ($afterLast->greaterThan($todayStart)) {
            $afterLast = $todayStart;
        }

        $covered = [];

        if ($afterLast->greaterThan($firstFull)) {
            $rollups = AnalyticsDaily::query()
                ->whereIn('user_id', $ids)
                ->whereNull('platform')
                ->whereDate('date', '>=', $firstFull->toDateString())
                ->whereDate('date', '<', $afterLast->toDateString())
                ->get(['id', 'user_id', 'date', 'messages_sent', 'avg_response_sec']);

            foreach ($rollups as $row) {
                $covered[CarbonImmutable::parse($row->date)->toDateString()] = true;
                $add((int) $row->user_id, (float) $row->avg_response_sec * (int) $row->messages_sent, (int) $row->messages_sent);
            }
        }

        // Live windows = the range minus the covered days.
        $windows = [];
        $cursor = $from;
        for ($day = $firstFull; $day->lessThan($afterLast); $day = $day->addDay()) {
            if (! isset($covered[$day->toDateString()])) {
                continue;
            }
            if ($cursor->lessThan($day->utc())) {
                $windows[] = [$cursor, $day->utc()->subSecond()];
            }
            $cursor = $day->addDay()->utc();
        }
        if ($cursor->lessThanOrEqualTo($to)) {
            $windows[] = [$cursor, $to];
        }

        foreach ($windows as [$windowFrom, $windowTo]) {
            $outbound = $this->humanOutbound($windowFrom, $windowTo, null, null)
                ->whereIn('user_id', $ids)
                ->get(['id', 'conversation_id', 'user_id', 'created_at']);

            foreach ($this->responseSamplesByUser($outbound) as $userId => $samples) {
                $add($userId, (float) array_sum($samples), count($samples));
            }
        }

        return $totals;
    }

    /**
     * @return array<int, array<int, int>> [weekday 0 (Sunday)–6][hour 0–23] => human outbound count, Africa/Cairo
     */
    public function hourlyHeatmap(?User $u, CarbonInterface $from, CarbonInterface $to): array
    {
        [$from, $to] = $this->normalize($from, $to);

        $grid = array_fill(0, 7, array_fill(0, 24, 0));

        foreach ($this->countsPerUtcHour($this->humanOutbound($from, $to, $u?->id, null)) as [$local, $n]) {
            $grid[$local->dayOfWeek][$local->hour] += $n;
        }

        return $grid;
    }

    // ---------------------------------------------------------------- user internals

    /**
     * @param  bool  $withOutcomes  false for long-range edge windows, whose caller computes outcomes over the whole range
     */
    private function liveUser(int $userId, CarbonImmutable $from, CarbonImmutable $to, ?Platform $platform, bool $withOutcomes = true): array
    {
        $p = $platform?->value;

        $outbound = $this->humanOutbound($from, $to, $userId, $p)
            ->get(['id', 'conversation_id', 'platform', 'created_at']);

        $roles = ConversationParticipant::query()
            ->where('user_id', $userId)
            ->whereBetween('first_message_at', [$from, $to])
            ->when($p, fn ($q) => $q->whereHas('conversation', fn ($c) => $c->where('platform', $p)))
            ->get(['role'])
            ->countBy(fn (ConversationParticipant $r) => $r->role->value);

        $logs = $this->logs(
            [
                ActivityLogger::CONVERSATION_FIRST_RESPONSE,
                ActivityLogger::CONVERSATION_RESOLVED,
                ActivityLogger::COMMENT_REPLIED,
                ActivityLogger::COMMENT_HIDDEN,
                ActivityLogger::COMMENT_PRIVATE_REPLY,
            ],
            $from, $to, $platform,
            fn ($q) => $q->where('user_id', $userId),
        );

        $firstResponse = $logs->where('action', ActivityLogger::CONVERSATION_FIRST_RESPONSE)
            ->map(fn (ActivityLog $l) => $l->meta['seconds'] ?? null)
            ->filter(fn ($s) => $s !== null)
            ->values()
            ->all();

        $responses = $this->responseSamples($outbound);

        $orders = Order::query()
            ->where('created_by_id', $userId)
            ->whereBetween('created_at', [$from, $to])
            ->whereNotIn('status', self::EXCLUDED_ORDER_STATUSES)
            ->when($p, fn ($q) => $q->where('platform', $p))
            ->get(['id', 'type', 'total', 'paid_at', 'platform', 'source']);

        $paymentLinks = $orders->where('type', OrderType::PaymentLink);

        $byPlatform = [];
        foreach (Platform::cases() as $case) {
            $byPlatform[$case->value] = [
                'messages_sent' => $outbound->filter(fn (Message $m) => $m->platform === $case)->count(),
                'orders_count' => $orders->filter(fn (Order $o) => $o->platform === $case)->count(),
            ];
        }

        return [
            'messages_sent' => $outbound->count(),
            'conversations_handled' => $outbound->pluck('conversation_id')->unique()->count(),
            'first_responses' => (int) ($roles[ParticipantRole::First->value] ?? 0),
            'continued' => (int) ($roles[ParticipantRole::Continued->value] ?? 0),
            'follow_ups' => (int) ($roles[ParticipantRole::FollowUp->value] ?? 0),
            'resolved' => $logs->where('action', ActivityLogger::CONVERSATION_RESOLVED)->count(),
            'avg_first_response_sec' => $this->avg($firstResponse),
            'avg_response_sec' => $this->avg($responses),
            'comments_handled' => $logs->whereIn('action', [ActivityLogger::COMMENT_REPLIED, ActivityLogger::COMMENT_HIDDEN, ActivityLogger::COMMENT_PRIVATE_REPLY])->count(),
            'private_replies' => $logs->where('action', ActivityLogger::COMMENT_PRIVATE_REPLY)->count(),
            'orders_count' => $orders->count(),
            'orders_total' => round((float) $orders->sum(fn (Order $o) => (float) $o->total), 2),
            'cod_count' => $orders->where('type', OrderType::Cod)->count(),
            'payment_link_count' => $paymentLinks->count(),
            'payment_link_paid' => $paymentLinks->whereNotNull('paid_at')->count(),
            'online_minutes' => $platform === null ? $this->onlineMinutes($userId, $from, $to) : 0,
            'by_platform' => $byPlatform,
            ...($withOutcomes ? $this->outcomeKeys(
                $orders->count(),
                (float) $orders->sum(fn (Order $o) => (float) $o->total),
                [
                    'chat' => $orders->filter(fn (Order $o) => $o->source === OrderSource::Chat)->count(),
                    'store' => $orders->filter(fn (Order $o) => $o->source === OrderSource::Store)->count(),
                ],
                $this->orderOutcomes($from, $to, $userId, $p),
            ) : []),
            '_fr_sum' => array_sum($firstResponse),
            '_fr_n' => count($firstResponse),
            '_rt_sum' => array_sum($responses),
            '_rt_n' => count($responses),
        ];
    }

    /**
     * Full past Cairo days come from analytics_daily (platform-null rows for totals, or the
     * platform's rows when filtered); only the partial first day and today are computed live.
     * Keys without a rollup column use narrow COUNT queries over the full range (no message scans).
     */
    private function longRangeUser(int $userId, CarbonImmutable $from, CarbonImmutable $to, ?Platform $platform): array
    {
        $p = $platform?->value;

        $fromLocal = $from->setTimezone(self::TZ);
        $firstFull = $fromLocal->startOfDay();
        if (! $firstFull->equalTo($fromLocal)) {
            $firstFull = $firstFull->addDay();
        }

        $afterLast = $to->setTimezone(self::TZ)->addSecond()->startOfDay();
        $todayStart = CarbonImmutable::now(self::TZ)->startOfDay();
        if ($afterLast->greaterThan($todayStart)) {
            $afterLast = $todayStart;
        }

        if ($afterLast->lessThanOrEqualTo($firstFull)) {
            return $this->liveUser($userId, $from, $to, $platform);
        }

        $edges = [];
        if ($firstFull->utc()->greaterThan($from)) {
            $edges[] = $this->liveUser($userId, $from, $firstFull->utc()->subSecond(), $platform, withOutcomes: false);
        }
        if ($afterLast->utc()->lessThanOrEqualTo($to)) {
            $edges[] = $this->liveUser($userId, $afterLast->utc(), $to, $platform, withOutcomes: false);
        }
        $edgeSum = fn (string $key) => array_sum(array_column($edges, $key));

        $rows = AnalyticsDaily::query()
            ->where('user_id', $userId)
            ->whereDate('date', '>=', $firstFull->toDateString())
            ->whereDate('date', '<', $afterLast->toDateString())
            ->when($p, fn ($q) => $q->where('platform', $p))
            ->get();

        $totals = $platform === null ? $rows->whereNull('platform') : $rows;

        $sums = [];
        foreach (self::ROLLUP_SUM_KEYS as $key) {
            $sums[$key] = (int) ($totals->sum($key) + $edgeSum($key));
        }

        $frSum = $edgeSum('_fr_sum') + $totals->sum(fn ($r) => $r->avg_first_response_sec * $r->first_responses);
        $frN = $edgeSum('_fr_n') + $totals->sum('first_responses');
        $rtSum = $edgeSum('_rt_sum') + $totals->sum(fn ($r) => $r->avg_response_sec * $r->messages_sent);
        $rtN = $edgeSum('_rt_n') + $totals->sum('messages_sent');

        $continued = TestScope::excludeConversations(ConversationParticipant::query())
            ->where('user_id', $userId)
            ->where('role', ParticipantRole::Continued->value)
            ->whereBetween('first_message_at', [$from, $to])
            ->when($p, fn ($q) => $q->whereHas('conversation', fn ($c) => $c->where('platform', $p)))
            ->count();

        $privateReplies = TestScope::excludeConversations(ActivityLog::query())
            ->where('user_id', $userId)
            ->where('action', ActivityLogger::COMMENT_PRIVATE_REPLY)
            ->whereBetween('created_at', [$from, $to])
            ->when($p, fn ($q) => $q->where('platform', $p))
            ->count();

        $orderTypes = Order::query()
            ->where('created_by_id', $userId)
            ->whereBetween('created_at', [$from, $to])
            ->whereNotIn('status', self::EXCLUDED_ORDER_STATUSES)
            ->when($p, fn ($q) => $q->where('platform', $p))
            ->selectRaw('type, count(*) as n, sum(case when paid_at is not null then 1 else 0 end) as paid')
            ->groupBy('type')
            ->toBase()
            ->get()
            ->keyBy('type');

        $byPlatform = [];
        foreach (Platform::cases() as $case) {
            $platformRows = $rows->filter(fn ($r) => $r->platform === $case);
            foreach (['messages_sent', 'orders_count'] as $key) {
                $byPlatform[$case->value][$key] = (int) ($platformRows->sum($key)
                    + array_sum(array_map(fn ($e) => $e['by_platform'][$case->value][$key], $edges)));
            }
        }

        return [
            'messages_sent' => $sums['messages_sent'],
            'conversations_handled' => $sums['conversations_handled'],
            'first_responses' => $sums['first_responses'],
            'continued' => $continued,
            'follow_ups' => $sums['follow_ups'],
            'resolved' => $sums['resolved'],
            'avg_first_response_sec' => $frN > 0 ? (int) round($frSum / $frN) : 0,
            'avg_response_sec' => $rtN > 0 ? (int) round($rtSum / $rtN) : 0,
            'comments_handled' => $sums['comments_handled'],
            'private_replies' => $privateReplies,
            'orders_count' => $sums['orders_count'],
            'orders_total' => round((float) $totals->sum(fn ($r) => (float) $r->orders_total) + $edgeSum('orders_total'), 2),
            'cod_count' => (int) ($orderTypes[OrderType::Cod->value]->n ?? 0),
            'payment_link_count' => (int) ($orderTypes[OrderType::PaymentLink->value]->n ?? 0),
            'payment_link_paid' => (int) ($orderTypes[OrderType::PaymentLink->value]->paid ?? 0),
            'online_minutes' => $platform === null ? $sums['online_minutes'] : 0,
            'by_platform' => $byPlatform,
        ] + $this->outcomeKeys(
            $sums['orders_count'],
            (float) $totals->sum(fn ($r) => (float) $r->orders_total) + $edgeSum('orders_total'),
            $this->createdBySource($userId, $from, $to, $p),
            // Order outcomes are aggregate queries, cheap over any range: computed
            // live so by_source always adds up (the rollup columns serve exports).
            $this->orderOutcomes($from, $to, $userId, $p),
        );
    }

    /**
     * Order outcomes (spec §6.3), all aggregate SQL so any range length is cheap:
     * COD orders whose shipment is delivered, dated by the latest `delivered` event;
     * other paid orders (financial_status paid|partially_refunded, or a COD order
     * with no CRM shipment) dated by paid_at, else created_at (processed_at is not
     * stored; the mapper already sets paid_at from it); minus refunds on those chat
     * orders dated by the refund. Cancelled/failed orders never count.
     *
     * @return array{delivered: int, returned: int, failed_final: int, revenue: float, revenue_by_source: array{chat: float, store: float}}
     */
    private function orderOutcomes(CarbonImmutable $from, CarbonImmutable $to, ?int $userId, ?string $platform): array
    {
        [$shipments, $money] = $this->orderOutcomeRows($from, $to, $userId !== null ? [$userId] : null, $platform);

        return $this->summarizeOutcomes($shipments, $money);
    }

    /**
     * The same outcomes for many users at once (leaderboard): still two queries.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array> user id => orderOutcomes() shape
     */
    private function orderOutcomesByUser(CarbonImmutable $from, CarbonImmutable $to, array $ids): array
    {
        [$shipments, $money] = $this->orderOutcomeRows($from, $to, $ids, null);
        $shipments = $shipments->groupBy(fn ($r) => (int) $r->user_id);
        $money = $money->groupBy(fn ($r) => (int) $r->user_id);

        $result = [];
        foreach ($ids as $id) {
            $result[$id] = $this->summarizeOutcomes($shipments->get($id, collect()), $money->get($id, collect()));
        }

        return $result;
    }

    /**
     * Two grouped queries per (created_by_id, source): shipment outcomes (delivered /
     * returned by their latest matching event, and cancelled-after-failed-attempt
     * "failed_final" by last_event_at) and money (paid orders plus negative refunds,
     * as one UNION ALL).
     *
     * @param  array<int, int>|null  $userIds  null = every order (team, store orders included)
     * @return array{0: Collection, 1: Collection}
     */
    private function orderOutcomeRows(CarbonImmutable $from, CarbonImmutable $to, ?array $userIds, ?string $platform): array
    {
        $scoped = fn () => Order::query()
            ->whereNotIn('orders.status', self::EXCLUDED_ORDER_STATUSES)
            ->when($userIds !== null, fn ($q) => $q->whereIn('orders.created_by_id', $userIds))
            ->when($platform !== null, fn ($q) => $q->where('orders.platform', $platform));

        $range = [$from->toDateTimeString(), $to->toDateTimeString()];
        $cod = OrderType::Cod->value;

        $lastEvent = ShipmentEvent::query()
            ->whereIn('status', [ShipmentStatus::Delivered->value, ShipmentStatus::Returned->value])
            ->selectRaw('shipment_id, status, max(occurred_at) as at')
            ->groupBy('shipment_id', 'status')
            ->havingRaw('max(occurred_at) between ? and ?', $range)
            ->toBase();

        $shipments = $scoped()
            ->join('shipments', 'shipments.order_id', '=', 'orders.id')
            ->leftJoinSub($lastEvent, 'last_event', fn ($join) => $join
                ->on('last_event.shipment_id', '=', 'shipments.id')
                ->on('last_event.status', '=', 'shipments.status'))
            ->where(fn ($q) => $q
                ->whereNotNull('last_event.shipment_id')
                ->orWhere(fn ($w) => $w
                    ->where('shipments.status', ShipmentStatus::Cancelled->value)
                    ->whereBetween('shipments.last_event_at', $range)
                    ->whereExists(fn ($e) => $e->selectRaw('1')
                        ->from('shipment_events')
                        ->whereColumn('shipment_events.shipment_id', 'shipments.id')
                        ->where('shipment_events.status', ShipmentStatus::FailedAttempt->value))))
            ->selectRaw('orders.created_by_id as user_id, orders.source as source, shipments.status as outcome, count(*) as n, sum(case when orders.type = ? then orders.total else 0 end) as cod_total', [$cod])
            ->groupBy('orders.created_by_id', 'orders.source', 'shipments.status')
            ->toBase()
            ->get();

        $hasShipment = fn ($q) => $q->selectRaw('1')->from('shipments')->whereColumn('shipments.order_id', 'orders.id');
        $paidRule = fn ($q) => $q->whereIn('orders.financial_status', self::REALIZED_FINANCIAL_STATUSES)
            ->where(fn ($w) => $w->where('orders.type', '!=', $cod)->orWhereNotExists($hasShipment));

        $paid = $scoped()
            ->where($paidRule)
            ->whereRaw('coalesce(orders.paid_at, orders.created_at) between ? and ?', $range)
            ->selectRaw('orders.created_by_id as user_id, orders.source as source, sum(orders.total) as amount')
            ->groupBy('orders.created_by_id', 'orders.source')
            ->toBase();

        // Store orders store Shopify's current_total_price, which is already net of
        // refunds: subtracting their refunds again would count them twice.
        $refunds = $scoped()
            ->where('orders.source', '!=', OrderSource::Store->value)
            ->join('refunds', 'refunds.order_id', '=', 'orders.id')
            ->whereRaw('coalesce(refunds.shopify_created_at, refunds.created_at) between ? and ?', $range)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('orders.type', $cod)->whereExists(fn ($s) => $hasShipment($s)->where('shipments.status', ShipmentStatus::Delivered->value)))
                ->orWhere($paidRule))
            ->selectRaw('orders.created_by_id as user_id, orders.source as source, 0 - sum(refunds.amount) as amount')
            ->groupBy('orders.created_by_id', 'orders.source')
            ->toBase();

        return [$shipments, $paid->unionAll($refunds)->get()];
    }

    /**
     * @return array{delivered: int, returned: int, failed_final: int, revenue: float, revenue_by_source: array{chat: float, store: float}}
     */
    private function summarizeOutcomes(Collection $shipments, Collection $money): array
    {
        $bySource = ['chat' => 0.0, 'store' => 0.0];
        $add = function (mixed $source, float $amount) use (&$bySource) {
            $bySource[(string) $source] = ($bySource[(string) $source] ?? 0.0) + $amount;
        };

        foreach ($shipments->where('outcome', ShipmentStatus::Delivered->value) as $r) {
            $add($r->source, (float) $r->cod_total);
        }
        foreach ($money as $r) {
            $add($r->source, (float) $r->amount);
        }

        $count = fn (ShipmentStatus $status) => (int) $shipments->where('outcome', $status->value)->sum(fn ($r) => (int) $r->n);

        return [
            'delivered' => $count(ShipmentStatus::Delivered),
            'returned' => $count(ShipmentStatus::Returned),
            'failed_final' => $count(ShipmentStatus::Cancelled),
            'revenue' => round(array_sum($bySource), 2),
            'revenue_by_source' => ['chat' => round($bySource['chat'], 2), 'store' => round($bySource['store'], 2)],
        ];
    }

    /**
     * @return array{chat: int, store: int} created (non-cancelled/failed) orders per source
     */
    private function createdBySource(?int $userId, CarbonImmutable $from, CarbonImmutable $to, ?string $platform): array
    {
        $counts = Order::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereNotIn('status', self::EXCLUDED_ORDER_STATUSES)
            ->when($userId !== null, fn ($q) => $q->where('created_by_id', $userId))
            ->when($platform !== null, fn ($q) => $q->where('platform', $platform))
            ->selectRaw('source, count(*) as n')
            ->groupBy('source')
            ->toBase()
            ->pluck('n', 'source');

        return ['chat' => (int) ($counts['chat'] ?? 0), 'store' => (int) ($counts['store'] ?? 0)];
    }

    /**
     * The realized-revenue keys appended to user and team metrics.
     *
     * @param  array{chat: int, store: int}  $createdBySource
     */
    private function outcomeKeys(int $createdCount, float $createdTotal, array $createdBySource, array $outcomes): array
    {
        $delivered = $outcomes['delivered'];
        $returned = $outcomes['returned'];
        $deliveryDenominator = $delivered + $returned + $outcomes['failed_final'];

        return [
            'orders_created_count' => $createdCount,
            'orders_created_total' => round($createdTotal, 2),
            'orders_delivered' => $delivered,
            'revenue_realized' => $outcomes['revenue'],
            'orders_returned' => $returned,
            'delivery_rate' => $deliveryDenominator > 0 ? round($delivered / $deliveryDenominator, 2) : 0.0,
            'return_rate' => ($delivered + $returned) > 0 ? round($returned / ($delivered + $returned), 2) : 0.0,
            'by_source' => [
                'chat' => ['created_count' => $createdBySource['chat'], 'revenue_realized' => $outcomes['revenue_by_source']['chat']],
                'store' => ['created_count' => $createdBySource['store'], 'revenue_realized' => $outcomes['revenue_by_source']['store']],
            ],
        ];
    }

    private function publicUser(array $raw): array
    {
        return array_filter($raw, fn ($key) => ! str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Response time per §5.3: for each outbound human message in the set, seconds since the
     * earliest customer message after the previous human/bot outbound in that conversation.
     *
     * @param  Collection<int, Message>  $outbound
     * @return array<int, int>
     */
    private function responseSamples(Collection $outbound): array
    {
        return array_merge([], ...array_values($this->responseSamplesByUser($outbound)));
    }

    /**
     * The same samples grouped by the replying user (`user_id` on the outbound rows, 0 when
     * not selected). The scan filters on sender_type only (no OR) and is bounded to one day
     * before the earliest reply, so it stays on the conversation/created_at index.
     *
     * @param  Collection<int, Message>  $outbound
     * @return array<int, array<int, int>>
     */
    private function responseSamplesByUser(Collection $outbound): array
    {
        if ($outbound->isEmpty()) {
            return [];
        }

        $targets = $outbound->mapWithKeys(fn (Message $m) => [(int) $m->id => (int) ($m->user_id ?? 0)]);
        $times = $outbound->pluck('created_at')->filter()->map(fn ($at) => CarbonImmutable::instance($at));

        $rows = Message::query()
            ->where('is_test', false)
            ->whereIn('conversation_id', $outbound->pluck('conversation_id')->unique()->values())
            ->whereBetween('created_at', [$times->min()->subDay(), $times->max()])
            ->whereIn('sender_type', [SenderType::Customer->value, SenderType::User->value, SenderType::Bot->value])
            ->orderBy('conversation_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->toBase()
            ->get(['id', 'conversation_id', 'direction', 'created_at', 'is_low_value', 'is_spam']);

        $samples = [];
        $conversation = null;
        $pending = null;

        foreach ($rows as $r) {
            if ((int) $r->conversation_id !== $conversation) {
                $conversation = (int) $r->conversation_id;
                $pending = null;
            }

            if ($r->direction === MessageDirection::In->value) {
                // A spam/low-value customer message (e.g. "شكرا 👍") never starts a
                // response-time sample (spec §11.1).
                if (! $r->is_low_value && ! $r->is_spam) {
                    $pending ??= $r->created_at;
                }

                continue;
            }

            if ($pending !== null && isset($targets[(int) $r->id])) {
                $samples[$targets[(int) $r->id]][] = (int) max(0, $this->utc($pending)->diffInSeconds($this->utc($r->created_at), false));
            }

            $pending = null;
        }

        return $samples;
    }

    private function onlineMinutes(int $userId, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $seconds = UserSession::query()
            ->where('user_id', $userId)
            ->where('started_at', '<=', $to)
            ->where(fn ($q) => $q->where('ended_at', '>=', $from)
                ->orWhere(fn ($w) => $w->whereNull('ended_at')->where('last_heartbeat_at', '>=', $from)))
            ->get()
            ->sum(function (UserSession $s) use ($from, $to) {
                $end = $s->ended_at ?? $s->last_heartbeat_at;
                if ($s->started_at === null || $end === null) {
                    return 0;
                }
                $start = $s->started_at->greaterThan($from) ? CarbonImmutable::instance($s->started_at) : $from;
                $end = $end->lessThan($to) ? CarbonImmutable::instance($end) : $to;

                return $end->greaterThan($start) ? (int) $start->diffInSeconds($end) : 0;
            });

        return intdiv((int) $seconds, 60);
    }

    // ---------------------------------------------------------------- shared helpers

    private function humanOutbound(CarbonImmutable $from, CarbonImmutable $to, ?int $userId, ?string $platform): Builder
    {
        return Message::query()
            ->where('is_test', false)
            ->whereBetween('created_at', [$from, $to])
            ->where('direction', MessageDirection::Out->value)
            ->where('sender_type', SenderType::User->value)
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->when($platform !== null, fn ($q) => $q->where('platform', $platform));
    }

    /**
     * Aggregates a message query per UTC hour in SQL (portable substr on the stored
     * "Y-m-d H:i:s" value, SQLite and MySQL/MariaDB), then shifts each bucket to Cairo in PHP.
     * At most 24 rows per day are returned, regardless of message volume.
     *
     * @return array<int, array{0: CarbonImmutable, 1: int}>
     */
    private function countsPerUtcHour(Builder $query): array
    {
        return $query
            ->selectRaw('substr(created_at, 1, 13) as utc_hour, count(*) as n')
            ->groupByRaw('substr(created_at, 1, 13)')
            ->toBase()
            ->get()
            ->map(fn ($r) => [
                CarbonImmutable::createFromFormat('!Y-m-d H', (string) $r->utc_hour, 'UTC')->setTimezone(self::TZ),
                (int) $r->n,
            ])
            ->all();
    }

    /**
     * Activity logs in range; the platform filter falls back to the conversation's platform,
     * then to the post platform of a comment subject, when the log row has none.
     *
     * @return Collection<int, ActivityLog>
     */
    private function logs(array $actions, CarbonImmutable $from, CarbonImmutable $to, ?Platform $platform, ?callable $scope = null): Collection
    {
        $logs = TestScope::excludeConversations(ActivityLog::query())
            ->whereIn('action', $actions)
            ->whereBetween('created_at', [$from, $to])
            ->when($scope !== null, fn ($q) => $scope($q))
            ->get();

        if ($platform === null) {
            return $logs;
        }

        $unknown = $logs->whereNull('platform');

        $conversationPlatforms = Conversation::whereIn('id', $unknown->pluck('conversation_id')->filter()->unique()->values())
            ->toBase()
            ->pluck('platform', 'id');

        $commentMorph = (new Comment)->getMorphClass();
        $commentPlatforms = Comment::query()
            ->join('posts', 'posts.id', '=', 'comments.post_id')
            ->whereIn('comments.id', $unknown->where('subject_type', $commentMorph)->pluck('subject_id')->unique()->values())
            ->toBase()
            ->pluck('posts.platform', 'comments.id');

        return $logs->filter(function (ActivityLog $l) use ($platform, $conversationPlatforms, $commentPlatforms, $commentMorph) {
            $value = $l->platform?->value
                ?? ($l->conversation_id !== null ? ($conversationPlatforms[$l->conversation_id] ?? null) : null)
                ?? ($l->subject_type === $commentMorph ? ($commentPlatforms[$l->subject_id] ?? null) : null);

            return $value === $platform->value;
        })->values();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function normalize(CarbonInterface $from, CarbonInterface $to): array
    {
        return [CarbonImmutable::instance($from)->utc(), CarbonImmutable::instance($to)->utc()];
    }

    private function avg(array $values): int
    {
        return $values === [] ? 0 : (int) round(array_sum($values) / count($values));
    }

    private function utc(string $at): CarbonImmutable
    {
        return CarbonImmutable::parse($at, 'UTC');
    }
}
