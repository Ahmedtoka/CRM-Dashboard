<?php

namespace App\Queue\Reports;

use App\Inbox\Outcomes\Outcome;
use App\Models\SupportCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reports › الفريق › «نتائج المحادثات» (2026-10-24, finish-api.md §5): what happened in the chats
 * that ended in a range of Cairo days — counts per outcome, the share of «ما ردّتش», purchases
 * against returns and complaints, complaints still open, the time to close, how often the
 * sheet's suggestion was followed — per day / week / month and per moderator. One computation
 * feeds `/reports/outcomes` and its CSV.
 *
 * An episode belongs to the Cairo day it ended (`conversation_outcomes.ended_at`); its moderator
 * is the person who picked the outcome, else the window's assignee; an automatic end with
 * neither (the bot, the idle sweep) is one «تلقائي» row. Test chats never count; `spam` and
 * `unknown` are counted but left out of every share.
 */
class OutcomesReport
{
    public const MAX_DAYS = 92;

    public const GROUPS = ['day', 'week', 'month'];

    /** The columns, in sheet order, then the two keys a person never picks. */
    public static function keys(): array
    {
        return [...Outcome::agentValues(), Outcome::Unknown->value];
    }

    /**
     * @return array{from: string, to: string, group: string, user_id: ?int, kpis: array<string, mixed>, by_outcome: array<string, int>, periods: list<array<string, mixed>>, rows: list<array<string, mixed>>}
     */
    public function build(string $from, string $to, string $group = 'day', ?int $userId = null): array
    {
        [$start, $end] = self::utcBounds($from, $to);
        $tz = 'Africa/Cairo';
        $group = in_array($group, self::GROUPS, true) ? $group : 'day';

        $rows = DB::table('conversation_outcomes as co')
            ->join('conversations as c', 'c.id', '=', 'co.conversation_id')
            ->leftJoin('queue_entries as e', 'e.id', '=', 'co.queue_entry_id')
            ->leftJoin('support_cases as sc', 'sc.id', '=', 'co.support_case_id')
            ->where('c.is_test', false)->whereBetween('co.ended_at', [$start, $end])
            ->where(fn ($q) => $q->whereNull('co.queue_entry_id')->orWhere('e.is_test', false))
            ->selectRaw('co.id, co.outcome, co.source, co.suggested_outcome, co.suggestion_followed, co.started_at, co.ended_at, co.reached_agent, '
                .'COALESCE(co.set_by_id, e.assigned_user_id) as user_id, e.handle_seconds, sc.type as case_type, sc.status as case_status')
            ->when($userId !== null, fn ($q) => $q->whereRaw('COALESCE(co.set_by_id, e.assigned_user_id) = ?', [$userId]))
            ->orderBy('co.id');

        $total = self::blank();
        $byUser = [];
        $byPeriod = [];

        foreach ($rows->lazyById(2000, 'co.id', 'id') as $r) {
            $u = $r->user_id !== null ? (int) $r->user_id : 0;
            $endedAt = CarbonImmutable::parse($r->ended_at, (string) config('app.timezone'))->setTimezone($tz);
            $period = self::periodKey($endedAt, $group);

            $byUser[$u] ??= self::blank();
            $byPeriod[$period] ??= self::blank() + ['key' => $period, 'from' => self::periodStart($endedAt, $group)->toDateString()];

            foreach ([&$total, &$byUser[$u], &$byPeriod[$period]] as &$acc) {
                self::add($acc, $r);
            }
            unset($acc);
        }

        ksort($byPeriod);
        $users = User::query()->whereIn('id', array_filter(array_keys($byUser)))->get(['id', 'name', 'color'])->keyBy('id');
        $perUser = [];
        foreach ($byUser as $id => $acc) {
            if ($id !== 0 && ! isset($users[$id])) {
                continue;
            }
            $perUser[] = ['user' => $id === 0 ? null : ['id' => $id, 'name' => $users[$id]->name, 'color' => $users[$id]->color]] + self::figures($acc);
        }
        usort($perUser, fn (array $x, array $y) => [$x['user'] === null, $y['total'], $x['user']['name'] ?? ''] <=> [$y['user'] === null, $x['total'], $y['user']['name'] ?? '']);

        return [
            'from' => $from,
            'to' => $to,
            'group' => $group,
            'user_id' => $userId,
            'kpis' => self::figures($total),
            'by_outcome' => $total['by_outcome'],
            'periods' => array_values(array_map(fn (array $acc) => ['key' => $acc['key'], 'from' => $acc['from']] + self::figures($acc), $byPeriod)),
            'rows' => $perUser,
        ];
    }

    /**
     * The CSV: one row per moderator with every outcome column, then one row per period.
     *
     * @return list<list<string|int|float|null>>
     */
    public function csvRows(array $report): array
    {
        $header = [__('outcomes.report.moderator'), __('outcomes.report.total')];
        foreach (self::keys() as $key) {
            $header[] = __('labels.outcomes.'.$key);
        }
        array_push($header, __('outcomes.report.no_reply_pct'), __('outcomes.report.purchases'), __('outcomes.report.returns'), __('outcomes.report.complaints'),
            __('outcomes.report.complaints_pending'), __('outcomes.report.avg_close_seconds'), __('outcomes.report.suggested'), __('outcomes.report.followed_pct'));

        $line = fn (string $name, array $row) => [
            self::cell($name), $row['total'],
            ...array_map(fn (string $key) => $row['by_outcome'][$key] ?? 0, self::keys()),
            $row['no_reply_pct'], $row['purchases'], $row['returns'], $row['complaints'], $row['complaints_pending'], $row['avg_close_seconds'], $row['suggested'], $row['followed_pct'],
        ];

        $out = [$header];
        foreach ($report['rows'] as $row) {
            $out[] = $line($row['user']['name'] ?? __('outcomes.report.automatic'), $row);
        }
        $out[] = $line(__('outcomes.report.all'), $report['kpis'] + ['by_outcome' => $report['by_outcome']]);
        foreach ($report['periods'] as $period) {
            $out[] = $line(__('outcomes.report.period').' '.$period['key'], $period);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function blank(): array
    {
        return ['total' => 0, 'by_outcome' => array_fill_keys(self::keys(), 0), 'reached' => 0, 'close_sum' => 0, 'close_n' => 0,
            'suggested' => 0, 'followed' => 0, 'complaints_pending' => 0, 'auto' => 0];
    }

    private static function add(array &$acc, object $r): void
    {
        $key = isset($acc['by_outcome'][$r->outcome]) ? $r->outcome : ($r->outcome === Outcome::Service->value ? Outcome::OrderInquiry->value : Outcome::Unknown->value);
        $acc['total']++;
        $acc['by_outcome'][$key]++;
        $acc['reached'] += (int) (bool) $r->reached_agent;
        $acc['auto'] += (int) ($r->source !== 'agent');

        if ($r->suggested_outcome !== null) {
            $acc['suggested']++;
            $acc['followed'] += (int) (bool) $r->suggestion_followed;
        }

        $seconds = $r->handle_seconds !== null ? (int) $r->handle_seconds
            : ($r->started_at !== null && $r->ended_at !== null ? max(0, strtotime((string) $r->ended_at) - strtotime((string) $r->started_at)) : null);
        if ($seconds !== null) {
            $acc['close_sum'] += $seconds;
            $acc['close_n']++;
        }

        if ($key === Outcome::ComplaintCase->value && $r->case_status !== null && in_array($r->case_status, SupportCase::OPEN_STATUSES, true)) {
            $acc['complaints_pending']++;
        }
    }

    /** @return array<string, mixed> */
    private static function figures(array $acc): array
    {
        $o = $acc['by_outcome'];
        // Every share leaves spam and the unrecorded out: they say nothing about how she was served.
        $counted = $acc['total'] - $o[Outcome::Spam->value] - $o[Outcome::Unknown->value];

        return [
            'total' => $acc['total'],
            'counted' => $counted,
            'by_outcome' => $o,
            'purchases' => $o[Outcome::Ordered->value],
            'lost' => array_sum(array_map(fn (string $k) => $o[$k] ?? 0, Outcome::lostValues())) - $o[Outcome::NoAnswer->value],
            'returns' => $o[Outcome::ReturnCase->value] + $o[Outcome::ExchangeCase->value],
            'complaints' => $o[Outcome::ComplaintCase->value],
            'complaints_pending' => $acc['complaints_pending'],
            'no_reply' => $o[Outcome::NoAnswer->value],
            'no_reply_pct' => $counted > 0 ? (int) round(100 * $o[Outcome::NoAnswer->value] / $counted) : null,
            'purchase_pct' => $counted > 0 ? (int) round(100 * $o[Outcome::Ordered->value] / $counted) : null,
            'avg_close_seconds' => $acc['close_n'] > 0 ? (int) round($acc['close_sum'] / $acc['close_n']) : null,
            'suggested' => $acc['suggested'],
            'followed' => $acc['followed'],
            'followed_pct' => $acc['suggested'] > 0 ? (int) round(100 * $acc['followed'] / $acc['suggested']) : null,
            'automatic' => $acc['auto'],
            'unknown' => $o[Outcome::Unknown->value],
        ];
    }

    private static function periodKey(CarbonImmutable $at, string $group): string
    {
        return match ($group) {
            'week' => $at->startOfWeek(CarbonImmutable::SATURDAY)->toDateString(),
            'month' => $at->format('Y-m'),
            default => $at->toDateString(),
        };
    }

    private static function periodStart(CarbonImmutable $at, string $group): CarbonImmutable
    {
        return match ($group) {
            'week' => $at->startOfWeek(CarbonImmutable::SATURDAY),
            'month' => $at->startOfMonth(),
            default => $at->startOfDay(),
        };
    }

    /** A text cell Excel would read as a formula (=, +, -, @) is written as text. */
    private static function cell(string $text): string
    {
        return preg_match('/^[=+\-@\t\r]/u', $text) === 1 ? "'".$text : $text;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function utcBounds(string $from, string $to): array
    {
        return [
            CarbonImmutable::createFromFormat('!Y-m-d', $from, 'Africa/Cairo')->startOfDay()->utc(),
            CarbonImmutable::createFromFormat('!Y-m-d', $to, 'Africa/Cairo')->endOfDay()->utc(),
        ];
    }
}
