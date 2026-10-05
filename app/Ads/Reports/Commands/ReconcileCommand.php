<?php

namespace App\Ads\Reports\Commands;

use App\Ads\Reports\Reconciliation;
use App\Ads\Sync\HistoryWindow;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The Phase A gate report (A11, R-34): per account and month, the CRM's account-level control next to the ad-level sums,
 * a blank Ads Manager column for the owner (or `--ads-manager="<account>=<amount>"` to have it checked), and the gate as
 * a checklist. The only thing it writes is ad_accounts.complete_from, which is always judged over history_start..yesterday,
 * whatever range is displayed.
 */
class ReconcileCommand extends Command
{
    protected $signature = 'ads:reconcile
        {--month= : A calendar month, YYYY-MM}
        {--from= : First day (default: the history start)}
        {--to= : Last day (default: yesterday)}
        {--account=* : Only these accounts (id, act_ id or exact name)}
        {--ads-manager=* : What Ads Manager shows for the range, as "<account>=<pre-tax spend>"}
        {--markdown : Print a Markdown table for docs/ads-review/06-phase0-facts.md}
        {--quiet-update : Print nothing; only update complete_from (the nightly run)}';

    protected $description = 'Compare the CRM ads totals with the account-level control and store each account\'s complete-from day';

    public function handle(Reconciliation $reconciliation): int
    {
        try {
            [$from, $to] = $this->range();
        } catch (Throwable $e) {
            $this->error($e instanceof \InvalidArgumentException && ! $e instanceof InvalidFormatException ? $e->getMessage() : 'Use --from and --to as YYYY-MM-DD.');

            return self::FAILURE;
        }

        $quiet = (bool) $this->option('quiet-update');
        $manager = $this->managerFigures();
        $results = $reconciliation->build($from, $to, (array) $this->option('account'));

        foreach ($results as $r) {
            $this->storeCompleteFrom($r);
        }
        if ($quiet) {
            return self::SUCCESS;
        }

        if ($results === []) {
            $this->warn('No active Meta account matches.');

            return self::SUCCESS;
        }

        $rows = $this->rows($results, $manager);
        if ($this->option('markdown')) {
            $this->markdown($rows);
        } else {
            $this->plain($rows);
        }
        $this->line('');
        $this->line('Compare "Control spend" with what Ads Manager shows for the same account and month (pre-tax, account currency).');
        foreach ($results as $r) {
            $this->gate($r, $this->managerFor($r, $manager));
        }

        return self::SUCCESS;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(): array
    {
        $tz = HistoryWindow::TIMEZONE;
        $month = trim((string) $this->option('month'));
        if ($month !== '') {
            if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                throw new \InvalidArgumentException('--month must look like 2026-09 (year, dash, two-digit month).');
            }
            $first = CarbonImmutable::createFromFormat('!Y-m', $month, $tz);

            return [$first->startOfMonth(), $first->endOfMonth()->startOfDay()];
        }

        $from = trim((string) $this->option('from'));
        $to = trim((string) $this->option('to'));

        return [
            $from !== '' ? CarbonImmutable::parse($from, $tz)->startOfDay() : HistoryWindow::start(),
            $to !== '' ? CarbonImmutable::parse($to, $tz)->startOfDay() : CarbonImmutable::now($tz)->subDay()->startOfDay(),
        ];
    }

    /** @return array<string, float> typed key => amount */
    private function managerFigures(): array
    {
        $out = [];
        foreach ((array) $this->option('ads-manager') as $entry) {
            $at = strrpos((string) $entry, '=');
            if ($at === false) {
                continue;
            }
            $amount = str_replace([',', ' '], '', substr((string) $entry, $at + 1));
            if (is_numeric($amount)) {
                $out[trim(substr((string) $entry, 0, $at))] = (float) $amount;
            }
        }

        return $out;
    }

    /** @param  array<string, float>  $manager */
    private function managerFor(array $r, array $manager): ?float
    {
        foreach ([(string) $r['account_id'], $r['external_id'], $r['name']] as $key) {
            if (isset($manager[$key])) {
                return $manager[$key];
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $r */
    private function storeCompleteFrom(array $r): void
    {
        $current = DB::table('ad_accounts')->where('id', $r['account_id'])->value('complete_from');
        $current = $current === null ? null : substr((string) $current, 0, 10);
        if ($current !== $r['complete_from']) {
            DB::table('ad_accounts')->where('id', $r['account_id'])->update(['complete_from' => $r['complete_from']]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @param  array<string, float>  $manager
     * @return list<list<string>>
     */
    private function rows(array $results, array $manager): array
    {
        $rows = [];
        foreach ($results as $r) {
            $figure = $this->managerFor($r, $manager);
            $lines = $r['months'];
            if (count($lines) !== 1) {
                $lines[] = ['month' => 'total'] + $r['total'];
            }
            foreach ($lines as $i => $m) {
                $isLast = $i === array_key_last($lines);
                $typed = $isLast ? $figure : null;
                $rows[] = [
                    $r['name'], (string) $m['month'],
                    "{$m['days_with_control']} / {$m['days_with_ads']} / {$m['days_expected']}",
                    $this->money($m['control_spend']), $this->money($m['ad_spend']),
                    $m['coverage_pct'] === null ? '-' : number_format($m['coverage_pct'], 2),
                    $this->money($m['residual']),
                    $this->money($m['control_purchase_value']), $this->money($m['ad_purchase_value']),
                    $typed === null ? '' : $this->money($typed),
                    $typed === null ? '' : $this->diff($m['control_spend'], $typed),
                    $isLast ? ($r['complete_from'] ?? '-') : '',
                ];
            }
        }

        return $rows;
    }

    private const HEADERS = ['Account', 'Month', 'Days (control / ads / expected)', 'Control spend (pre-tax)', 'Sum ad spend', 'Coverage %', 'Residual not itemised', 'Control purchase value', 'Sum ad purchase value', 'Ads Manager', 'Diff %', 'complete_from'];

    /** @param  list<list<string>>  $rows */
    private function plain(array $rows): void
    {
        $this->table(self::HEADERS, $rows);
    }

    /** @param  list<list<string>>  $rows */
    private function markdown(array $rows): void
    {
        $this->line('| '.implode(' | ', self::HEADERS).' |');
        $this->line('|'.str_repeat(' --- |', count(self::HEADERS)));
        foreach ($rows as $row) {
            $this->line('| '.implode(' | ', $row).' |');
        }
    }

    /** @param  array<string, mixed>  $r */
    private function gate(array $r, ?float $manager): void
    {
        $t = $r['total'];
        $tolerance = (float) config('crm.ads.control_tolerance_pct', 0.5);
        $this->line('');
        $this->line("Gate for {$r['name']} ({$r['external_id']}, {$r['currency']}) {$r['from']} to {$r['to']}");

        if ($manager === null) {
            $this->line("  [TODO] 1. Ads Manager total within {$tolerance}% of the control: type it with --ads-manager=\"{$r['account_id']}=<amount>\" (control: {$this->money($t['control_spend'])})");
        } else {
            $diff = $manager > 0 ? abs($t['control_spend'] - $manager) / $manager * 100 : 100.0;
            $this->line(sprintf('  [%s] 1. Ads Manager total within %s%% of the control: Ads Manager %s, control %s, difference %s%%', $diff <= $tolerance ? 'PASS' : 'FAIL', $tolerance, $this->money($manager), $this->money($t['control_spend']), number_format($diff, 2)));
        }

        $cov = $t['coverage_pct'];
        $this->line(sprintf('  [%s] 2. Ad-level coverage at least %s%%: %s%%, residual Meta no longer itemises by ad: %s', $cov !== null && $cov >= Reconciliation::COVERAGE_PCT ? 'PASS' : 'WARN', Reconciliation::COVERAGE_PCT, $cov === null ? '-' : number_format($cov, 2), $this->money($t['residual'])));

        $gap = $t['purchase_value_gap_pct'];
        $this->line(sprintf('  [%s] 3. Purchase value within %s%%: ads %s vs control %s (%s%%); compare the control with Ads Manager yourself', $gap !== null && abs($gap) <= Reconciliation::PURCHASE_VALUE_PCT ? 'PASS' : 'WARN', Reconciliation::PURCHASE_VALUE_PCT, $this->money($t['ad_purchase_value']), $this->money($t['control_purchase_value']), $gap === null ? '-' : number_format($gap, 2)));

        $ok = $r['complete_from'] === $r['history_start'];
        $this->line(sprintf('  [%s] 4. History complete from %s: complete_from = %s', $ok ? 'PASS' : 'FAIL', $r['history_start'], $r['complete_from'] ?? 'none'));
        if (! $ok && $r['uncovered_days'] !== []) {
            $days = $r['uncovered_days'];
            $shown = implode(', ', array_slice($days, 0, 15)).(count($days) > 15 ? ' ... ('.count($days).' days)' : '');
            $this->line("         days without an ok sync with a working account-totals call: {$shown}");
        }
    }

    private function money(float|int $v): string
    {
        return number_format((float) $v, 2);
    }

    private function diff(float $control, float $manager): string
    {
        return $manager > 0 ? number_format(($control - $manager) / $manager * 100, 2) : '-';
    }
}
