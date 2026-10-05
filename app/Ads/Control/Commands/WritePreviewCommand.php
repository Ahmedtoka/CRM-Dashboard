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
 * ASSUMPTION A1 (Meta budgets are in the account currency's minor unit) before the budget cap is trusted. Writes no row,
 * not even an audit row, and never prints a token.
 */
class WritePreviewCommand extends Command
{
    protected $signature = 'ads:write-preview
        {--account= : Ad account id or external id (act_...)}
        {--level= : campaign, adset or ad}
        {--id= : The object\'s external id}';

    protected $description = 'Read one ad object live and show what a CRM Run would see (status, budgets); read-only';

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
            $type->target($account, $level, $id);
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

        return self::SUCCESS;
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
