<?php

namespace App\Queue\Reports;

use App\Models\QueueEntry;
use App\Models\ScoreEvent;
use App\Models\SupportCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The leader's report («تقرير الليدر», /reports/team): one row per moderator for a range of Cairo
 * business days — windows received and handled, the MEDIAN first reply (one slow morning must not
 * drag an average), silence auto-closes, hand-offs (no reply / transfer), ratings, points, the open
 * cases she owes right now, and the recorded outcomes of her windows counted by key.
 *
 * The outcome keys are never hard-coded: whatever `conversation_outcomes.outcome` holds in the
 * range is a column (`outcome_keys`, sorted), so a renamed or new outcome shows up by itself.
 * Test and load-test windows (`queue_entries.is_test`) never count, nor do the cases on them.
 */
class TeamReport
{
    public const MAX_DAYS = QueueReport::MAX_DAYS;

    /** @return array{from: string, to: string, too_long: bool, outcome_keys: list<string>, rows: list<array<string, mixed>>, totals: array<string, mixed>} */
    public function build(string $from, string $to): array
    {
        // The team page takes any range; beyond the report's reach it shows nothing rather than a slow or wrong table.
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1 > self::MAX_DAYS) {
            return ['from' => $from, 'to' => $to, 'too_long' => true, 'outcome_keys' => [], 'rows' => [], 'totals' => self::finish(self::blank(), [])];
        }

        $acc = [];
        $all = self::blank();
        $bounds = QueueReport::storedBounds($from, $to);

        $entries = DB::table('queue_entries')->where('is_test', false)->whereBetween('business_date', $bounds)
            ->whereNotNull('assigned_user_id')->whereNotNull('delivered_at')
            ->select(['id', 'assigned_user_id', 'status', 'close_reason', 'delivered_at', 'first_reply_at', 'review_stars', 'rule']);

        foreach ($entries->lazyById(2000, 'id') as $e) {
            $acc[(int) $e->assigned_user_id] ??= self::blank();
            self::window($acc[(int) $e->assigned_user_id], $e);
            self::window($all, $e);
        }

        $points = ScoreEvent::query()->toBase()->whereBetween('business_date', [$from, $to])
            ->selectRaw('user_id, SUM(points) as total')->groupBy('user_id')->get();
        foreach ($points as $p) {
            $acc[(int) $p->user_id] ??= self::blank();
            $acc[(int) $p->user_id]['points'] = (int) $p->total;
        }
        $all['points'] = (int) $points->sum('total');

        // Live (now, whatever the range): the open cases she answers for — the payee of the case
        // (CaseResolution::payeeOf: her window, else who opened it) or whoever it is assigned to.
        $cases = DB::table('support_cases as c')->leftJoin('queue_entries as e', 'e.id', '=', 'c.queue_entry_id')
            ->whereIn('c.status', SupportCase::OPEN_STATUSES)
            ->where(fn ($q) => $q->whereNull('e.id')->orWhere('e.is_test', false))
            ->selectRaw('c.id, COALESCE(e.assigned_user_id, c.opened_by_id) as payee, c.assigned_to_id')->get();
        foreach ($cases as $c) {
            foreach (array_unique(array_filter([$c->payee, $c->assigned_to_id])) as $u) {
                $acc[(int) $u] ??= self::blank();
                $acc[(int) $u]['open_cases']++;
            }
        }
        $all['open_cases'] = $cases->count();

        // The outcomes of her windows in the range, by whatever key the recorder wrote.
        $outcomes = DB::table('conversation_outcomes as o')->join('queue_entries as e', 'e.id', '=', 'o.queue_entry_id')
            ->where('e.is_test', false)->whereNotNull('e.assigned_user_id')->whereBetween('e.business_date', $bounds)
            ->selectRaw('e.assigned_user_id as user_id, o.outcome, COUNT(*) as n')->groupBy('e.assigned_user_id', 'o.outcome')->get();
        $keys = [];
        foreach ($outcomes as $o) {
            $key = (string) $o->outcome;
            $keys[$key] = true;
            $acc[(int) $o->user_id] ??= self::blank();
            $acc[(int) $o->user_id]['outcomes'][$key] = (int) $o->n;
            $all['outcomes'][$key] = ($all['outcomes'][$key] ?? 0) + (int) $o->n;
        }
        $keys = array_keys($keys);
        sort($keys);

        $users = User::query()->whereIn('id', array_keys($acc))->get(['id', 'name', 'color'])->keyBy('id');
        $rows = [];
        foreach ($acc as $id => $r) {
            if (! isset($users[$id])) {
                continue;
            }
            $rows[] = ['user' => ['id' => $users[$id]->id, 'name' => $users[$id]->name, 'color' => $users[$id]->color]] + self::finish($r, $keys);
        }
        usort($rows, fn (array $x, array $y) => [$y['handled'], $x['user']['name']] <=> [$x['handled'], $y['user']['name']]);

        return ['from' => $from, 'to' => $to, 'too_long' => false, 'outcome_keys' => $keys, 'rows' => $rows, 'totals' => self::finish($all, $keys)];
    }

    /**
     * The CSV of a report: a header row in the viewer's language, one row per moderator, then the
     * team's line. An outcome column is labelled by its translation when one exists, else by its key.
     *
     * @return list<list<string|int|float|null>>
     */
    public function csvRows(array $report): array
    {
        $fields = ['received', 'handled', 'first_reply_median', 'auto_closes', 'hand_offs', 'hand_offs_no_reply', 'hand_offs_transfer',
            'rating_avg', 'rating_count', 'points', 'open_cases'];
        $header = [__('queue.team_report.moderator')];
        foreach ($fields as $f) {
            $header[] = __('queue.team_report.'.$f);
        }
        foreach ($report['outcome_keys'] as $key) {
            $header[] = self::outcomeLabel($key);
        }

        $line = function (string $name, array $r) use ($report): array {
            $out = [self::cell($name), $r['received'], $r['handled'], $r['first_reply_median_seconds'], $r['auto_closes'], $r['hand_offs'],
                $r['hand_offs_no_reply'], $r['hand_offs_transfer'], $r['rating_avg'], $r['rating_count'], $r['points'], $r['open_cases']];
            foreach ($report['outcome_keys'] as $key) {
                $out[] = $r['outcomes'][$key] ?? 0;
            }

            return $out;
        };

        $out = [$header];
        foreach ($report['rows'] as $row) {
            $out[] = $line($row['user']['name'], $row);
        }
        $out[] = $line(__('queue.team_report.totals'), $report['totals']);

        return $out;
    }

    /** `labels.outcomes.{key}` in the viewer's language, or the raw key for an outcome no translation knows yet. */
    public static function outcomeLabel(string $key): string
    {
        $k = 'labels.outcomes.'.$key;

        return ($label = __($k)) !== $k ? (string) $label : $key;
    }

    /** @return array<string, mixed> */
    private static function blank(): array
    {
        return ['received' => 0, 'handled' => 0, 'replies' => [], 'auto_closes' => 0, 'hand_offs_no_reply' => 0, 'hand_offs_transfer' => 0,
            'stars_sum' => 0, 'stars_n' => 0, 'points' => 0, 'open_cases' => 0, 'outcomes' => []];
    }

    /** One delivered window into an accumulator (a moderator's or the team's). */
    private static function window(array &$row, object $e): void
    {
        $row['received']++;

        if ($e->status === 'closed' && $e->close_reason !== null) {
            $row['handled'] += (int) in_array($e->close_reason, QueueEntry::HANDLED_REASONS, true);
            $row['auto_closes'] += (int) ($e->close_reason === 'auto');
            $row['hand_offs_no_reply'] += (int) ($e->close_reason === 'no_reply');
            $row['hand_offs_transfer'] += (int) ($e->close_reason === 'transfer');
        }

        // RC-8: a claimed window's first reply is the claim itself — it has no first-reply time.
        if ($e->first_reply_at !== null && $e->rule !== 'claim') {
            $row['replies'][] = max(0, self::ts($e->first_reply_at) - self::ts($e->delivered_at));
        }

        if ($e->review_stars !== null) {
            $row['stars_sum'] += (int) $e->review_stars;
            $row['stars_n']++;
        }
    }

    /**
     * An accumulator as a report row: the median first reply, the hand-offs total, and a count for
     * every outcome key of the range (zero when she had none), so the columns line up.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private static function finish(array $r, array $keys): array
    {
        $outcomes = [];
        foreach ($keys as $k) {
            $outcomes[$k] = $r['outcomes'][$k] ?? 0;
        }

        return [
            'received' => $r['received'],
            'handled' => $r['handled'],
            'first_reply_median_seconds' => self::median($r['replies']),
            'auto_closes' => $r['auto_closes'],
            'hand_offs' => $r['hand_offs_no_reply'] + $r['hand_offs_transfer'],
            'hand_offs_no_reply' => $r['hand_offs_no_reply'],
            'hand_offs_transfer' => $r['hand_offs_transfer'],
            'rating_avg' => $r['stars_n'] > 0 ? round($r['stars_sum'] / $r['stars_n'], 1) : null,
            'rating_count' => $r['stars_n'],
            'points' => $r['points'],
            'open_cases' => $r['open_cases'],
            'outcomes' => $outcomes,
        ];
    }

    /**
     * The median of a list of seconds: the middle value, or the mean of the two middle values for an
     * even count, rounded to whole seconds; null when there is nothing to measure.
     *
     * @param  list<int>  $values
     */
    private static function median(array $values): ?int
    {
        $n = count($values);
        if ($n === 0) {
            return null;
        }
        sort($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : (int) round(($values[$mid - 1] + $values[$mid]) / 2);
    }

    /** A text cell Excel would read as a formula (=, +, -, @) is written as text. */
    private static function cell(string $text): string
    {
        return preg_match('/^[=+\-@\t\r]/u', $text) === 1 ? "'".$text : $text;
    }

    private static function ts(string $value): int
    {
        return CarbonImmutable::parse($value, (string) config('app.timezone'))->getTimestamp();
    }
}
