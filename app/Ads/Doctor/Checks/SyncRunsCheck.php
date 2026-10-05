<?php

namespace App\Ads\Doctor\Checks;

use App\Ads\Doctor\DoctorRow;
use App\Ads\Sync\SyncAdAccount;
use App\Models\AdAccount;
use App\Models\AdsSyncRun;

class SyncRunsCheck extends DoctorCheck
{
    protected function section(): string
    {
        return 'Sync runs';
    }

    public function run(): array
    {
        $rows = [];
        $cutoff = now()->subSeconds((new SyncAdAccount(0))->timeout + 600);
        $stuck = AdsSyncRun::query()->where('status', 'running')->where('started_at', '<', $cutoff)->count();
        $rows[] = DoctorRow::by($stuck === 0, 'fail', 'Sync runs', 'stuck running rows', (string) $stuck, 'Runs stuck in "running" past the job timeout: the worker died. ads:sweep-stuck-runs ends them.');

        $accounts = $this->scopeAccounts(AdAccount::query()->where('is_active', true))->orderBy('id')->get(['id', 'name']);
        foreach ($accounts as $a) {
            $run = AdsSyncRun::query()->where('ad_account_id', $a->id)->orderByDesc('id')->first(['status', 'started_at', 'error']);
            if ($run === null) {
                $rows[] = DoctorRow::warn('Sync runs', "last run {$a->name}", 'no run yet');

                continue;
            }
            $age = $run->started_at === null ? '?' : (int) $run->started_at->diffInMinutes(now(), true).' min ago';
            $bad = $run->status === 'error';
            $rows[] = new DoctorRow('Sync runs', "last run {$a->name}", $bad ? 'warn' : 'ok', "{$run->status}, {$age}", $bad ? self::clean($run->error) : '');
        }

        $groups = [];
        foreach (AdsSyncRun::query()->where('status', 'error')->where('started_at', '>=', now()->subDay())->pluck('error') as $e) {
            $k = self::clean($e, 40);
            $groups[$k] = ($groups[$k] ?? 0) + 1;
        }
        arsort($groups);
        if ($groups === []) {
            $rows[] = DoctorRow::ok('Sync runs', 'errors, 24 h', '0');
        }
        foreach ($groups as $text => $n) {
            $rows[] = DoctorRow::warn('Sync runs', 'errors, 24 h', "{$n}x", (string) $text);
        }

        return $rows;
    }
}
