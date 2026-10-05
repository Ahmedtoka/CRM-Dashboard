<?php

namespace App\Ads\Doctor\Checks;

use App\Ads\Control\WritableAccounts;
use App\Ads\Doctor\DoctorRow;
use App\Ads\Sync\HistoryWindow;
use App\Models\AdAccount;
use Illuminate\Support\Facades\DB;

class AccountsCheck extends DoctorCheck
{
    protected function section(): string
    {
        return 'Accounts';
    }

    public function run(): array
    {
        $accounts = $this->scopeAccounts(AdAccount::query())->with('connection:id,status')->orderBy('id')->get();
        if ($accounts->isEmpty()) {
            return [DoctorRow::skip('Accounts', 'ad_accounts', 'none')];
        }

        $start = HistoryWindow::start()->toDateString();
        $stats = DB::table('ad_daily_metrics')
            ->selectRaw('ad_account_id, MIN(date) as mn, MAX(date) as mx, SUM(CASE WHEN date < ? THEN 1 ELSE 0 END) as pre', [$start])
            ->groupBy('ad_account_id')->get()->keyBy('ad_account_id');
        $lastOk = DB::table('ads_sync_runs')->where('status', 'ok')->selectRaw('ad_account_id, MAX(finished_at) as at')->groupBy('ad_account_id')->pluck('at', 'ad_account_id');

        $rows = [];
        foreach ($accounts as $a) {
            $s = $stats[$a->id] ?? null;
            $tz = (string) $a->timezone;
            $pre = (int) ($s->pre ?? 0);
            $notes = [];
            if ($tz !== '' && $tz !== 'Africa/Cairo') {
                $notes[] = "timezone {$tz} is not Africa/Cairo (R-20)";
            }
            if ($pre > 0) {
                $notes[] = "{$pre} metric rows before {$start} (input for ads:prune-history)";
            }
            $value = implode(' | ', [
                "ext {$a->external_id}", "currency {$a->currency}", 'tz '.($tz === '' ? '?' : $tz),
                'active '.($a->is_active ? 'yes' : 'no'), 'writable '.(WritableAccounts::allows($a) ? 'yes' : 'no'),
                'connection '.($a->connection?->status ?? 'none'), 'last ok '.($lastOk[$a->id] ?? 'never'),
                'dates '.($s ? "{$s->mn}..{$s->mx}" : 'none'),
            ]);
            $rows[] = new DoctorRow('Accounts', "#{$a->id} ".self::clean($a->name, 60), $notes === [] ? 'ok' : 'warn', $value, implode('; ', $notes));
        }

        return $rows;
    }
}
