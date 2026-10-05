<?php

namespace App\Ads\Control\Commands;

use App\Ads\Audit\AdsAudit;
use App\Ads\Control\WritableAccounts;
use App\Models\AdAccount;
use Illuminate\Console\Command;

/** Optional override of the writable accounts. Without it every active account is writable. */
class WritableAccountsCommand extends Command
{
    protected $signature = 'ads:writable {--list : Show the mode and the writable accounts} {--set= : Comma list of external ids (act_...) that stay writable} {--all : Back to every active account}';

    protected $description = 'Show or narrow the ad accounts the CRM may write to (default: every active account)';

    public function handle(): int
    {
        $set = $this->option('set');
        $all = (bool) $this->option('all');

        if ($all && $set !== null) {
            $this->error('Use either --set or --all.');

            return self::FAILURE;
        }

        if ($all) {
            $this->change(null);
        } elseif ($set !== null) {
            $ids = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $set)), fn ($v) => $v !== '')));
            if ($ids === []) {
                $this->error('--set needs at least one external id. Use --all to clear.');

                return self::FAILURE;
            }
            $missing = array_values(array_diff($ids, AdAccount::query()->whereIn('external_id', $ids)->pluck('external_id')->all()));
            if ($missing !== []) {
                $this->error('Unknown account(s): '.implode(', ', $missing));

                return self::FAILURE;
            }
            $this->change($ids);
        }

        $this->show();

        return self::SUCCESS;
    }

    /** @param  list<string>|null  $ids */
    private function change(?array $ids): void
    {
        $before = WritableAccounts::list();
        WritableAccounts::set($ids);
        AdsAudit::record('settings.writable_accounts_changed', null, ['writable' => $before ?? 'all_active'], ['writable' => $ids ?? 'all_active']);
    }

    private function show(): void
    {
        $list = WritableAccounts::list();
        $this->line('Mode: '.($list === null ? 'all active accounts' : 'only '.implode(', ', $list)));
        $rows = AdAccount::query()->where('is_active', true)->orderBy('id')->get()
            ->map(fn (AdAccount $a) => [$a->id, $a->external_id, $a->name, WritableAccounts::allows($a) ? 'yes' : 'no'])->all();
        $this->table(['id', 'external id', 'name', 'writable'], $rows);
    }
}
