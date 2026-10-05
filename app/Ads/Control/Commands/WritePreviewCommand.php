<?php

namespace App\Ads\Control\Commands;

use App\Ads\Control\Write\RunGuard;
use App\Ads\Control\Write\Types\SetStatusType;
use App\Ads\Control\Write\WriteDenied;
use App\Ads\Control\Write\WriteLimits;
use App\Ads\Control\Write\WritePolicy;
use App\Ads\Platforms\Data\ObjectState;
use App\Models\AdAccount;
use Illuminate\Console\Command;

/**
 * Read-only: one live read of a campaign, ad set or ad, printed with its budgets in minor and major units. Used to check
 * ASSUMPTION A1 (Meta budgets are in the account currency's minor unit) before the budget cap is trusted. Writes no
 * action and no audit row, and never prints a token. A live Meta read records its usage headers in ads_api_usage
 * (telemetry, Meta\UsageRecorder) like every Meta call; nothing else is written.
 */
class WritePreviewCommand extends Command
{
    protected $signature = 'ads:write-preview
        {--account= : Ad account id or external id (act_...)}
        {--level= : campaign, adset or ad}
        {--id= : The object\'s external id}';

    protected $description = 'Read one ad object live and show what a CRM Run would see (status, budgets, cap verdict); writes nothing except Meta ads_api_usage telemetry';

    public function handle(RunGuard $guard, SetStatusType $type): int
    {
        $needle = trim((string) $this->option('account'));
        $level = (string) $this->option('level');
        $id = trim((string) $this->option('id'));

        $account = ctype_digit($needle) && $needle !== '' ? AdAccount::query()->find((int) $needle) : null;
        $account ??= $needle !== '' ? AdAccount::query()->where('external_id', $needle)->first() : null;
        if ($account === null) {
            $this->error("No ad account matches {$needle}.");

            return self::FAILURE;
        }
        if (! in_array($level, WritePolicy::LEVELS, true) || $id === '') {
            $this->error('Give --level=campaign|adset|ad and --id=<external id>.');

            return self::FAILURE;
        }

        try {
            $target = $type->target($account, $level, $id);
            $live = $guard->read($account, $level, $id);
        } catch (WriteDenied $e) {
            $this->error($e->errorCode.': '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line("{$account->name} ({$account->external_id}) {$level} {$id}");
        $this->line('status: '.($live->status ?? '-'));
        $this->line('effective status: '.($live->effectiveStatus ?? '-'));
        $this->line('currency: '.$live->currency);
        foreach ($this->budgetLines($level, $live) as $line) {
            $this->line($line);
        }
        $this->line('ends: '.($live->endsAt?->toIso8601String() ?? '-'));
        $this->line($this->verdictLine($guard->budgetVerdict(null, $account, $target, $live), $live->currency));

        return self::SUCCESS;
    }

    /** The Run guard's budget verdict for this account (global/account limits; a user override is not applied). */
    private function verdictLine(array $verdict, string $currency): string
    {
        $caps = array_values(array_filter($verdict['rows'], fn ($r) => $r['key'] === 'max_daily_budget'));
        $cap = $caps[0]['limit'] ?? null;
        $capText = $cap !== null ? ' cap '.WriteLimits::money((int) $cap, $currency).' a day' : '';
        $refusal = $verdict['refusal'];
        if ($refusal !== null) {
            $d = $refusal->details;
            $what = isset($d['per_day_minor']) ? " ({$d['object_level']} ".WriteLimits::money((int) $d['per_day_minor'], $currency).' a day >'.$capText.')' : '';

            return 'cap verdict: refused '.$refusal->errorCode.$what;
        }
        $largest = $caps === [] ? 0 : max(array_column($caps, 'requested'));

        return 'cap verdict: allowed (largest '.WriteLimits::money((int) $largest, $currency).' a day,'.$capText.')';
    }

    /** @return list<string> */
    private function budgetLines(string $level, ObjectState $live): array
    {
        $money = fn (?int $minor) => $minor === null ? '-' : WriteLimits::money($minor, $live->currency);
        $lines = [
            "{$level} daily budget: ".$money($live->dailyBudgetMinor),
            "{$level} lifetime budget: ".$money($live->lifetimeBudgetMinor),
        ];
        foreach ($live->parents as $p) {
            $lines[] = "{$p['level']} ({$p['status']}) daily budget: ".$money($p['dailyBudgetMinor'])
                .', lifetime budget: '.$money($p['lifetimeBudgetMinor'])
                .', ends: '.($p['endsAt']?->toIso8601String() ?? '-');
        }

        return $lines;
    }
}
