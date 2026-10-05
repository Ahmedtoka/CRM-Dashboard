<?php

namespace App\Ads\Control\Commands;

use App\Ads\Audit\AdsAudit;
use App\Ads\Control\WritableAccounts;
use App\Models\AdAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** The per-account write switch (ad_accounts.write_enabled). Every account is writable by default (D3). */
class WritableAccountsCommand extends Command
{
    protected $signature = 'ads:writable
        {--list : Show every account and its write flag (default)}
        {--set= : Comma list of external ids (act_...) that stay writable; every other account is switched off}
        {--all : Switch writes back on for every active account}
        {--enable= : Comma list of external ids to switch on}
        {--disable= : Comma list of external ids to switch off}';

    protected $description = 'Show or change the ad accounts the CRM may write to (default: every active account)';

    public function handle(): int
    {
        $set = $this->option('set');
        $enable = $this->option('enable');
        $disable = $this->option('disable');
        $all = (bool) $this->option('all');

        $modes = count(array_filter([$set !== null, $enable !== null, $disable !== null, $all]));
        if ($modes > 1) {
            $this->error('Use only one of --set, --all, --enable, --disable.');

            return self::FAILURE;
        }

        if ($all) {
            $this->apply(AdAccount::query()->where('is_active', true)->pluck('id')->all(), []);
        } elseif ($set !== null || $enable !== null || $disable !== null) {
            $ids = $this->ids((string) ($set ?? $enable ?? $disable));
            if ($ids === null) {
                return self::FAILURE;
            }
            $named = AdAccount::query()->whereIn('external_id', $ids)->pluck('id')->all();
            match (true) {
                $set !== null => $this->apply($named, AdAccount::query()->whereNotIn('id', $named)->pluck('id')->all()),
                $enable !== null => $this->apply($named, []),
                default => $this->apply([], $named),
            };
            if ($set !== null) {
                $this->line('New accounts found later are writable by default (D3); disable them with --disable.');
            }
        }

        $this->show();

        return self::SUCCESS;
    }

    /** @return list<string>|null null when empty or an id is unknown (error printed) */
    private function ids(string $raw): ?array
    {
        $ids = array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)), fn ($v) => $v !== '')));
        if ($ids === []) {
            $this->error('Give at least one external id (act_...).');

            return null;
        }
        $missing = array_values(array_diff($ids, AdAccount::query()->whereIn('external_id', $ids)->pluck('external_id')->all()));
        if ($missing !== []) {
            $this->error('Unknown account(s): '.implode(', ', $missing).'. Nothing changed.');

            return null;
        }

        return $ids;
    }

    /**
     * @param  list<int>  $on
     * @param  list<int>  $off
     */
    private function apply(array $on, array $off): void
    {
        DB::transaction(function () use ($on, $off) {
            foreach ([[true, $on], [false, $off]] as [$value, $ids]) {
                if ($ids === []) {
                    continue;
                }
                $changed = AdAccount::query()->whereIn('id', $ids)->where('write_enabled', ! $value)->orderBy('id')->lockForUpdate()->get();
                foreach ($changed as $a) {
                    $a->forceFill(['write_enabled' => $value])->save();
                    AdsAudit::record('account.write_enabled_changed', $a, ['write_enabled' => ! $value], ['write_enabled' => $value]);
                    $this->line(($value ? 'Enabled ' : 'Disabled ').$a->external_id);
                }
            }
        });
    }

    private function show(): void
    {
        $rows = AdAccount::query()->orderBy('id')->get()
            ->map(fn (AdAccount $a) => [$a->id, $a->external_id, $a->name, $a->is_active ? 'yes' : 'no', $a->write_enabled ? 'yes' : 'no', WritableAccounts::allows($a) ? 'yes' : 'no'])->all();
        $this->table(['id', 'external id', 'name', 'active', 'write_enabled', 'writable'], $rows);
    }
}
