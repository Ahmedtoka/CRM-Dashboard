<?php

namespace App\Ads\Reports;

use App\Ads\Sync\HistoryWindow;
use App\Models\AdAccount;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A11 / R-34: per account and month, what the CRM holds next to the account-level control (what Ads Manager shows for the
 * whole account), and from which day the history is complete. Reads only; saving `complete_from` is the caller's job
 * (ReconcileCommand), so this class never writes.
 *
 * Days are the account's own `date` days (Meta date_start). Spend is pre-tax, as Meta reports it.
 */
final class Reconciliation
{
    /** Gate item 2: ad-level spend must reach this share of the control (the rest is shown as the residual). */
    public const COVERAGE_PCT = 99.0;

    /** Gate item 3: purchase value of the ads against the control, in percent (the owner compares with Ads Manager). */
    public const PURCHASE_VALUE_PCT = 2.0;

    /**
     * @param  list<string>  $accountKeys  ids, external ids or exact names; empty = every active Meta account
     * @return list<array<string, mixed>>
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to, array $accountKeys = []): array
    {
        $out = [];
        foreach ($this->accounts($accountKeys) as $a) {
            $out[] = $this->account($a, $from, $to);
        }

        return $out;
    }

    /**
     * @param  list<string>  $keys
     * @return Collection<int, AdAccount>
     */
    public function accounts(array $keys): Collection
    {
        $q = AdAccount::query()->where('platform', 'meta')->where('is_active', true)->orderBy('id');
        $keys = array_values(array_filter(array_map(fn ($k) => trim((string) $k), $keys), fn ($k) => $k !== ''));
        if ($keys !== []) {
            $q->where(function ($w) use ($keys) {
                $w->whereIn('external_id', $keys)->orWhereIn('name', $keys);
                $ids = array_values(array_filter($keys, fn ($k) => ctype_digit($k)));
                if ($ids !== []) {
                    $w->orWhereIn('id', $ids);
                }
            });
        }

        return $q->get();
    }

    /** @return array<string, mixed> */
    public function account(AdAccount $a, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tz = $a->timezone ?: HistoryWindow::TIMEZONE;
        $yesterday = CarbonImmutable::now($tz)->subDay()->toDateString();
        $start = HistoryWindow::start()->toDateString();
        $first = max($from->toDateString(), $start);
        $last = min($to->toDateString(), $yesterday);

        // Displayed rows: only the requested window. complete_from below never depends on it.
        $control = $this->control($a->id, $first, $last);
        $ads = $this->ads($a->id, $first, $last);

        $complete = $this->completeness($a);

        $months = [];
        $total = $this->blank($first, $last);
        if ($first <= $last) {
            foreach (CarbonPeriod::create($first, $last) as $day) {
                $d = $day->toDateString();
                $m = substr($d, 0, 7);
                $months[$m] ??= $this->blank($d, $d, $m);
                $months[$m]['to'] = $d;
                $this->addDay($months[$m], $d, $control[$d] ?? null, $ads[$d] ?? null);
                $this->addDay($total, $d, $control[$d] ?? null, $ads[$d] ?? null);
            }
        }
        foreach ($months as &$m) {
            $this->finish($m);
        }
        unset($m);
        $this->finish($total);

        return [
            'account_id' => (int) $a->id,
            'name' => (string) $a->name,
            'external_id' => (string) $a->external_id,
            'currency' => (string) ($a->currency ?: 'EGP'),
            'timezone' => $tz,
            'from' => $first,
            'to' => $last,
            'months' => array_values($months),
            'total' => $total,
            'complete_from' => $complete['complete_from'],
            'uncovered_days' => $complete['uncovered_days'],
            'history_start' => $start,
        ];
    }

    /**
     * Whether the history is complete, judged over history_start..yesterday (the account's own timezone) whatever range is
     * displayed. A day is covered when an ok sync run covers it and that run's account-level control call answered (a run
     * whose error carries the `Account totals:` warning had no control, so it covers nothing). A missing control row on a
     * covered day is an idle day (Meta lists no row for zero delivery) and counts as 0, not as a gap.
     * complete_from = the earliest day from which every day up to yesterday is covered; null when yesterday is not.
     *
     * @return array{complete_from: ?string, uncovered_days: list<string>}
     */
    public function completeness(AdAccount $a): array
    {
        $tz = $a->timezone ?: HistoryWindow::TIMEZONE;
        $start = HistoryWindow::start()->toDateString();
        $yesterday = CarbonImmutable::now($tz)->subDay()->toDateString();
        if ($yesterday < $start) {
            return ['complete_from' => null, 'uncovered_days' => []];
        }

        $covered = $this->syncCoverage($a->id, $start, $yesterday);
        $uncovered = [];
        $from = null;
        foreach (CarbonPeriod::create($start, $yesterday) as $day) {
            $d = $day->toDateString();
            if (! isset($covered[$d])) {
                $uncovered[] = $d;
            }
        }
        if ($uncovered === []) {
            $from = $start;
        } elseif (end($uncovered) !== $yesterday) {
            $from = CarbonImmutable::parse(end($uncovered))->addDay()->toDateString();
        }

        return ['complete_from' => $from, 'uncovered_days' => $uncovered];
    }

    /** @return array<string, array{spend: float, value: float}> */
    private function control(int $accountId, string $from, string $to): array
    {
        $out = [];
        foreach (DB::table('ad_account_daily')->where('ad_account_id', $accountId)->whereBetween('date', [$from, $to])->get(['date', 'spend', 'purchase_value']) as $r) {
            $out[substr((string) $r->date, 0, 10)] = ['spend' => (float) $r->spend, 'value' => (float) $r->purchase_value];
        }

        return $out;
    }

    /** @return array<string, array{spend: float, value: float}> */
    private function ads(int $accountId, string $from, string $to): array
    {
        $out = [];
        $rows = DB::table('ad_daily_metrics')->where('ad_account_id', $accountId)->whereBetween('date', [$from, $to])
            ->groupBy('date')->selectRaw('date, SUM(spend) as spend, SUM(purchase_value) as value')->get();
        foreach ($rows as $r) {
            $out[substr((string) $r->date, 0, 10)] = ['spend' => (float) $r->spend, 'value' => (float) $r->value];
        }

        return $out;
    }

    /** @return array<string, true> days covered by an ok sync run of this account */
    private function syncCoverage(int $accountId, string $from, string $to): array
    {
        $out = [];
        $runs = DB::table('ads_sync_runs')->where('ad_account_id', $accountId)->where('status', 'ok')
            ->where(fn ($w) => $w->whereNull('error')->orWhere('error', 'not like', '%Account totals:%'))
            ->whereNotNull('from_date')->whereNotNull('to_date')->where('to_date', '>=', $from)->where('from_date', '<=', $to)
            ->get(['from_date', 'to_date']);
        foreach ($runs as $r) {
            $a = max(substr((string) $r->from_date, 0, 10), $from);
            $b = min(substr((string) $r->to_date, 0, 10), $to);
            if ($a <= $b) {
                foreach (CarbonPeriod::create($a, $b) as $day) {
                    $out[$day->toDateString()] = true;
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function blank(string $from, string $to, ?string $month = null): array
    {
        return [
            'month' => $month, 'from' => $from, 'to' => $to,
            'days_expected' => 0, 'days_with_control' => 0, 'days_with_ads' => 0, 'missing_control_days' => [],
            'control_spend' => 0.0, 'ad_spend' => 0.0, 'coverage_pct' => null, 'residual' => 0.0,
            'control_purchase_value' => 0.0, 'ad_purchase_value' => 0.0, 'purchase_value_gap_pct' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{spend: float, value: float}|null  $control
     * @param  array{spend: float, value: float}|null  $ads
     */
    private function addDay(array &$row, string $day, ?array $control, ?array $ads): void
    {
        $row['days_expected']++;
        if ($control !== null) {
            $row['days_with_control']++;
            $row['control_spend'] += $control['spend'];
            $row['control_purchase_value'] += $control['value'];
        } else {
            $row['missing_control_days'][] = $day;
        }
        if ($ads !== null) {
            $row['days_with_ads']++;
            $row['ad_spend'] += $ads['spend'];
            $row['ad_purchase_value'] += $ads['value'];
        }
    }

    /** @param  array<string, mixed>  $row */
    private function finish(array &$row): void
    {
        foreach (['control_spend', 'ad_spend', 'control_purchase_value', 'ad_purchase_value'] as $k) {
            $row[$k] = round($row[$k], 2);
        }
        $row['coverage_pct'] = $row['control_spend'] > 0 ? round($row['ad_spend'] / $row['control_spend'] * 100, 2) : null;
        $row['residual'] = round($row['control_spend'] - $row['ad_spend'], 2);
        $row['purchase_value_gap_pct'] = $row['control_purchase_value'] > 0
            ? round(($row['ad_purchase_value'] - $row['control_purchase_value']) / $row['control_purchase_value'] * 100, 2) : null;
    }
}
