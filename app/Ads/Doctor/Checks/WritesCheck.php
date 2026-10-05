<?php

namespace App\Ads\Doctor\Checks;

use App\Ads\Control\Write\WriteLimits;
use App\Ads\Control\Write\WriteSwitch;
use App\Ads\Doctor\DoctorRow;
use App\Ads\Sync\QueueInspector;
use App\Models\AdAccount;
use App\Models\AdWriteAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Phase B write controls and the phase exit checks. Read-only (SELECTs and a Redis length read). */
class WritesCheck extends DoctorCheck
{
    private const S = 'Writes';

    protected function section(): string
    {
        return self::S;
    }

    public function run(): array
    {
        if (! Schema::hasTable('ad_write_actions')) {
            return [DoctorRow::fail(self::S, 'ad_write_actions', 'missing', 'Run the migrations.')];
        }

        $rows = [];

        $config = WriteSwitch::configEnabled();
        $setting = WriteSwitch::settingEnabled();
        $rows[] = DoctorRow::by($config && $setting, 'warn', self::S, 'kill switch',
            'config '.($config ? 'on' : 'off').', setting '.($setting ? 'on' : 'off').', effective '.($config && $setting ? 'on' : 'off'),
            'CRM Runs and publishing are off (Stop still works). ads:writes --on to switch back.');

        $holders = User::query()->where('ads_authority', true)->where('is_active', true)->orderBy('id')->pluck('name');
        $rows[] = DoctorRow::by($holders->isNotEmpty(), 'fail', self::S, 'Ads-authority holders',
            $holders->count().($holders->isNotEmpty() ? ': '.self::clean($holders->implode(', '), 120) : ''),
            'Nobody can act on campaigns or ad sets or receive Stop failure notices. ads:authority --grant=<email>.');

        $locked = AdAccount::query()->where('is_active', true)->where('write_enabled', false)->orderBy('id')->pluck('name');
        $rows[] = DoctorRow::ok(self::S, 'accounts not writable', $locked->isEmpty() ? 'none' : $locked->count().': '.self::clean($locked->implode(', '), 120),
            $locked->isEmpty() ? '' : 'Changed with ads:writable (owner choice).');

        $limits = app(WriteLimits::class);
        $set = $limits->stored()['global'] !== [];
        $value = collect($limits->for(null, null))->map(fn ($v, $k) => "{$k} {$v}")->implode(', ');
        $rows[] = DoctorRow::by($set, 'warn', self::S, 'write limits', $value, 'Still the config defaults (ASSUMPTION): confirm them with ads:write-limits --set.');

        $queue = (string) config('crm.ads.write.retry_queue', 'commerce');
        $len = app(QueueInspector::class)->lengths($queue);
        $overdue = AdWriteAction::query()->where('state', AdWriteAction::EXECUTING)->where('to_status', 'paused')
            ->whereNotNull('retry_at')->where('retry_at', '<', now()->subMinutes(5))->count();
        $rows[] = DoctorRow::by($overdue === 0, 'warn', self::S, 'retry queue',
            "{$queue}: ".($len === null ? 'length unknown (not Redis)' : "ready {$len['ready']}, delayed {$len['delayed']}").", Stop retries overdue >5 min {$overdue}",
            'A confirmed Stop retry is overdue: is the worker for this queue running? ads:write-sweep re-dispatches it.');

        // Phase B exit: every platform change that succeeded was confirmed by a person.
        $exit = (int) DB::table('ad_write_actions as a')->join('ad_write_steps as s', 's.ad_write_action_id', '=', 'a.id')
            ->where('s.state', 'succeeded')->whereNull('a.confirmed_by_id')->where('a.source', '<>', 'legacy')->count();
        $rows[] = DoctorRow::by($exit === 0, 'fail', self::S, 'exit query: succeeded steps without confirmed_by_id', (string) $exit,
            'A platform change has no confirming user: investigate before closing Phase B.');

        $stale = now()->subMinutes(10);
        $unresolved = AdWriteAction::query()->where(fn ($q) => $q->where('state', AdWriteAction::UNKNOWN)
            ->orWhere(fn ($w) => $w->where('state', AdWriteAction::EXECUTING)->whereNull('retry_at')->where('executing_at', '<', $stale)))->count();
        $rows[] = DoctorRow::by($unresolved === 0, 'fail', self::S, 'unresolved writes', (string) $unresolved,
            'Check Ads Manager, then ads:write-resolve <id> --succeeded|--failed --note="...".');

        if (Schema::hasTable('ad_actions')) {
            $old = DB::table('ad_actions')->count();
            // Only the copied rows (source_ref ad_actions:{id}); the legacy shim also writes source=legacy rows, with no source_ref.
            $copied = AdWriteAction::query()->where('source', 'legacy')->where('source_ref', 'like', 'ad_actions:%')->count();
            // Not migrate:refresh: that migration's down() also deletes the rows the legacy endpoint wrote since B1.
            $rows[] = DoctorRow::by($old === $copied, 'fail', self::S, 'legacy copy', "ad_actions {$old}, legacy {$copied}",
                "The B1 copy is incomplete. Re-run only its up() (idempotent, copies the missing rows): php artisan tinker --execute=\"(require base_path('database/migrations/2026_10_07_100050_copy_ad_actions_to_ad_write_actions.php'))->up();\"");
        }

        $rows[] = DoctorRow::skip(self::S, 'legacy endpoint calls', 'log only',
            'grep -c ads.legacy_write_endpoint storage/logs/laravel*.log (remove the shim after 14 days at 0)');

        return $rows;
    }
}
