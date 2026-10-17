<?php

namespace App\Queue\Reports;

use App\Models\QueueEntry;
use App\Models\ScoreEvent;
use App\Models\SupportCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The queue's end-of-day report for any range of Cairo business days (queue Part 2, §5.4): the
 * KPI tiles and one row per moderator — windows received, closes by reason, first reply and
 * handle time, SLA, customer ratings, the leader's QA, cases opened and resolved, and points per
 * kind. One computation feeds `/reports/queue`, its CSV and the nightly archive (QueueRollup).
 *
 * Test and load-test windows (`queue_entries.is_test`) never count; their points were never
 * written. A window belongs to its business day; a resolved case to the Cairo day it was
 * resolved; a point to the day of the work it rewards (ScoreKeeper).
 */
class QueueReport
{
    public const MAX_DAYS = 92;

    /** Closes that are "other" in the per-reason columns of the CSV. */
    public const OTHER_REASONS = ['transfer', 'resolved_elsewhere', 'cancelled'];

    /** @return array{from: string, to: string, kpis: array<string, mixed>, rows: list<array<string, mixed>>} */
    public function build(string $from, string $to): array
    {
        $acc = [];
        $kpi = ['tickets' => 0, 'windows' => 0, 'handled' => 0, 'closed' => array_fill_keys(QueueEntry::CLOSE_REASONS, 0),
            'reply_sum' => 0, 'reply_n' => 0, 'handle_sum' => 0, 'handle_n' => 0, 'sla_met' => 0, 'sla_n' => 0, 'stars_sum' => 0, 'stars_n' => 0];

        $entries = DB::table('queue_entries')->where('is_test', false)
            ->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)
            ->select(['id', 'assigned_user_id', 'status', 'close_reason', 'ticket_no', 'delivered_at', 'first_reply_at', 'handle_seconds', 'sla_met', 'review_stars']);

        foreach ($entries->lazyById(2000, 'id') as $e) {
            $kpi['tickets'] += (int) $e->ticket_no < 100000 ? 1 : 0;

            if ($e->assigned_user_id === null || $e->delivered_at === null) {
                continue;
            }

            $u = (int) $e->assigned_user_id;
            $acc[$u] ??= self::blank();
            $row = &$acc[$u];
            $row['received']++;
            $kpi['windows']++;

            if ($e->status === 'closed' && $e->close_reason !== null) {
                $row['closed'][$e->close_reason] = ($row['closed'][$e->close_reason] ?? 0) + 1;
                $kpi['closed'][$e->close_reason] = ($kpi['closed'][$e->close_reason] ?? 0) + 1;

                if (in_array($e->close_reason, QueueEntry::HANDLED_REASONS, true)) {
                    $row['handled']++;
                    $kpi['handled']++;

                    if ($e->handle_seconds !== null) {
                        $row['handle_sum'] += (int) $e->handle_seconds;
                        $row['handle_n']++;
                        $kpi['handle_sum'] += (int) $e->handle_seconds;
                        $kpi['handle_n']++;
                    }
                }
            }

            if ($e->first_reply_at !== null) {
                $secs = max(0, self::ts($e->first_reply_at) - self::ts($e->delivered_at));
                $row['reply_sum'] += $secs;
                $row['reply_n']++;
                $row['sla_met'] += (int) (bool) $e->sla_met;
                $kpi['reply_sum'] += $secs;
                $kpi['reply_n']++;
                $kpi['sla_met'] += (int) (bool) $e->sla_met;
            }

            if ($e->review_stars !== null) {
                $row['stars_sum'] += (int) $e->review_stars;
                $row['stars_n']++;
                $kpi['stars_sum'] += (int) $e->review_stars;
                $kpi['stars_n']++;
            }

            unset($row);
        }

        $points = ScoreEvent::query()->toBase()->whereBetween('business_date', [$from, $to])
            ->selectRaw('user_id, kind, SUM(points) as total')->groupBy('user_id', 'kind')->get();
        foreach ($points as $p) {
            $acc[(int) $p->user_id] ??= self::blank();
            $acc[(int) $p->user_id]['points'][(string) $p->kind] = (int) $p->total;
        }

        $qa = DB::table('qa_reviews')->whereBetween('business_date', [$from, $to])
            ->selectRaw('user_id, COUNT(*) as n, AVG(score) as avg')->groupBy('user_id')->get();
        foreach ($qa as $q) {
            $acc[(int) $q->user_id] ??= self::blank();
            $acc[(int) $q->user_id]['qa_n'] = (int) $q->n;
            $acc[(int) $q->user_id]['qa_avg'] = round((float) $q->avg, 1);
        }

        [$start, $end] = self::utcBounds($from, $to);
        $resolved = SupportCase::query()->toBase()->whereNotNull('opened_by_id')->whereNotNull('queue_entry_id')
            ->where('status', 'resolved')->whereBetween('resolved_at', [$start, $end])
            ->whereNotIn('queue_entry_id', DB::table('queue_entries')->where('is_test', true)->select('id'))
            ->selectRaw('opened_by_id, COUNT(*) as n, SUM(CASE WHEN sla_due_at IS NULL OR resolved_at <= sla_due_at THEN 1 ELSE 0 END) as in_time')
            ->groupBy('opened_by_id')->get();
        foreach ($resolved as $c) {
            $acc[(int) $c->opened_by_id] ??= self::blank();
            $acc[(int) $c->opened_by_id]['cases_resolved'] = (int) $c->n;
        }

        $users = User::query()->whereIn('id', array_keys($acc))->get(['id', 'name', 'color'])->keyBy('id');
        $rows = [];
        foreach ($acc as $id => $r) {
            if (! isset($users[$id])) {
                continue;
            }
            $rows[] = self::row($users[$id], $r);
        }
        usort($rows, fn (array $x, array $y) => [$y['total'], $x['user']['name']] <=> [$x['total'], $y['user']['name']]);

        return [
            'from' => $from,
            'to' => $to,
            'kpis' => [
                'tickets' => $kpi['tickets'],
                'windows' => $kpi['windows'],
                'handled' => $kpi['handled'],
                'closed' => $kpi['closed'],
                'first_reply_avg_seconds' => self::avg($kpi['reply_sum'], $kpi['reply_n']),
                'handle_avg_seconds' => self::avg($kpi['handle_sum'], $kpi['handle_n']),
                'sla_pct' => $kpi['reply_n'] > 0 ? (int) round(100 * $kpi['sla_met'] / $kpi['reply_n']) : null,
                'rating_avg' => $kpi['stars_n'] > 0 ? round($kpi['stars_sum'] / $kpi['stars_n'], 1) : null,
                'rating_count' => $kpi['stars_n'],
                'cases_opened' => $kpi['closed']['case'] ?? 0,
                'cases_resolved' => (int) $resolved->sum('n'),
                'cases_resolved_in_time' => (int) $resolved->sum('in_time'),
                'cases_overdue' => SupportCase::query()->whereIn('status', SupportCase::OPEN_STATUSES)->whereNotNull('opened_by_id')
                    ->whereNotNull('sla_due_at')->where('sla_due_at', '<', now())->count(),
                'points_total' => (int) $points->sum('total'),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * The CSV of a report: a header row in the viewer's language, then one row per moderator.
     *
     * @return list<list<string|int|float|null>>
     */
    public function csvRows(array $report): array
    {
        $reasons = ['inquiry', 'problem', 'case', 'auto', 'escalation', 'no_reply'];
        $kinds = ScoreEvent::KINDS;
        $header = [__('queue.report.moderator'), __('queue.report.received')];
        foreach ($reasons as $r) {
            $header[] = __('queue.report.closed.'.$r);
        }
        array_push($header, __('queue.report.closed.other'), __('queue.report.first_reply_avg'), __('queue.report.handle_avg'), __('queue.report.sla_pct'),
            __('queue.report.rating_avg'), __('queue.report.rating_count'), __('queue.report.qa_avg'), __('queue.report.qa_count'), __('queue.report.cases_resolved'));
        foreach ($kinds as $k) {
            $header[] = __('queue.report.points.'.$k);
        }
        $header[] = __('queue.report.total');

        $out = [$header];
        foreach ($report['rows'] as $row) {
            $line = [$row['user']['name'], $row['received']];
            foreach ($reasons as $r) {
                $line[] = $row['closed'][$r] ?? 0;
            }
            $other = array_sum(array_map(fn ($r) => $row['closed'][$r] ?? 0, self::OTHER_REASONS));
            array_push($line, $other, $row['first_reply_avg_seconds'], $row['handle_avg_seconds'], $row['sla_pct'],
                $row['rating_avg'], $row['rating_count'], $row['qa_avg'], $row['qa_count'], $row['cases_resolved']);
            foreach ($kinds as $k) {
                $line[] = $row['points'][$k] ?? 0;
            }
            $line[] = $row['total'];
            $out[] = $line;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function blank(): array
    {
        return ['received' => 0, 'handled' => 0, 'closed' => [], 'reply_sum' => 0, 'reply_n' => 0, 'sla_met' => 0, 'handle_sum' => 0, 'handle_n' => 0,
            'stars_sum' => 0, 'stars_n' => 0, 'qa_n' => 0, 'qa_avg' => null, 'cases_resolved' => 0, 'points' => []];
    }

    /** @return array<string, mixed> */
    private static function row(User $u, array $r): array
    {
        $points = array_fill_keys(ScoreEvent::KINDS, 0);
        foreach ($r['points'] as $k => $v) {
            $points[$k] = $v;
        }

        return [
            'user' => ['id' => $u->id, 'name' => $u->name, 'color' => $u->color],
            'received' => $r['received'],
            'closed' => $r['closed'],
            'handled' => $r['handled'],
            'first_reply_avg_seconds' => self::avg($r['reply_sum'], $r['reply_n']),
            'handle_avg_seconds' => self::avg($r['handle_sum'], $r['handle_n']),
            'sla_pct' => $r['reply_n'] > 0 ? (int) round(100 * $r['sla_met'] / $r['reply_n']) : null,
            'rating_avg' => $r['stars_n'] > 0 ? round($r['stars_sum'] / $r['stars_n'], 1) : null,
            'rating_count' => $r['stars_n'],
            'qa_avg' => $r['qa_avg'],
            'qa_count' => $r['qa_n'],
            'cases_opened' => $r['closed']['case'] ?? 0,
            'cases_resolved' => $r['cases_resolved'],
            'points' => $points,
            'total' => array_sum($points),
        ];
    }

    private static function avg(int $sum, int $n): ?int
    {
        return $n > 0 ? (int) round($sum / $n) : null;
    }

    private static function ts(string $value): int
    {
        return CarbonImmutable::parse($value, (string) config('app.timezone'))->getTimestamp();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private static function utcBounds(string $from, string $to): array
    {
        return [
            CarbonImmutable::createFromFormat('!Y-m-d', $from, 'Africa/Cairo')->startOfDay()->utc(),
            CarbonImmutable::createFromFormat('!Y-m-d', $to, 'Africa/Cairo')->endOfDay()->utc(),
        ];
    }
}
