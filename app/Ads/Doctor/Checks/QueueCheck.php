<?php

namespace App\Ads\Doctor\Checks;

use App\Ads\Doctor\DoctorRow;
use App\Ads\Sync\QueueInspector;
use App\Ads\Sync\SyncAdAccount;
use App\Models\AdsSyncRun;
use Illuminate\Support\Carbon;

class QueueCheck extends DoctorCheck
{
    protected function section(): string
    {
        return 'Queue';
    }

    public function run(): array
    {
        $rows = [];
        $default = (string) config('queue.default');
        $rows[] = DoctorRow::ok('Queue', 'queue.default', $default);

        foreach ((array) config('queue.connections', []) as $name => $conf) {
            if (is_array($conf) && isset($conf['retry_after'])) {
                $rows[] = DoctorRow::ok('Queue', "retry_after {$name}", (string) $conf['retry_after']);
            }
        }

        // SyncAdAccount runs on `redislong` when the default is redis, else on the default connection.
        $conn = $default === 'redis' ? 'redislong' : $default;
        $retry = config("queue.connections.{$conn}.retry_after");
        $timeout = (new SyncAdAccount(0))->timeout;
        $rows[] = $retry === null
            ? DoctorRow::skip('Queue', 'retry_after vs SyncAdAccount timeout', "connection {$conn} has no retry_after")
            : DoctorRow::by((int) $retry > $timeout, 'fail', 'Queue', 'retry_after vs SyncAdAccount timeout', "{$conn}: retry_after {$retry}, timeout {$timeout}", 'retry_after must exceed the job timeout, or a second worker re-runs a job that is still running.');

        $syncQueue = SyncAdAccount::queueName();
        $queues = array_values(array_unique(['commercelong', $syncQueue]));
        $inspector = app(QueueInspector::class);
        foreach ($queues as $q) {
            $len = $inspector->lengths($q);
            $rows[] = $len === null
                ? DoctorRow::skip('Queue', "length {$q}", 'not a Redis queue or Redis unreachable')
                : DoctorRow::ok('Queue', "length {$q}", "ready {$len['ready']}, delayed {$len['delayed']}, reserved {$len['reserved']}");
            if ($q === 'adssync' && $q === $syncQueue && $len !== null) {
                $rows[] = $this->adssyncWorker($len['ready']);
            }
        }

        $store = (string) config('cache.default');
        $rows[] = DoctorRow::ok('Queue', 'cache store', $store);
        $qdb = (string) config('database.redis.default.database');
        $cdb = (string) config('database.redis.cache.database');
        $rows[] = DoctorRow::by(! ($store === 'redis' && $qdb === $cdb), 'fail', 'Queue', 'redis databases', "default {$qdb}, cache {$cdb}", 'Queue and cache share one Redis database: a cache flush deletes queued jobs (P3).');

        $deploy = base_path('deploy.sh');
        $rows[] = is_file($deploy)
            ? DoctorRow::by(! str_contains((string) file_get_contents($deploy), 'optimize:clear'), 'fail', 'Queue', 'deploy.sh keeps the cache', 'no optimize:clear', 'deploy.sh contains optimize:clear: every deploy wipes locks and back-offs.')
            : DoctorRow::skip('Queue', 'deploy.sh keeps the cache', 'deploy.sh not found');

        return $rows;
    }

    /**
     * The adssync lane exists only once the owner adds the crm-adssync Supervisor program. Jobs ready to run while no
     * sync run finished in 15 minutes means nothing consumes the lane (program missing, FATAL or stuck).
     */
    private function adssyncWorker(int $ready): DoctorRow
    {
        $last = AdsSyncRun::query()->whereNotNull('finished_at')->max('finished_at');
        $recent = $last !== null && Carbon::parse($last)->greaterThanOrEqualTo(now()->subMinutes(15));
        $value = "ready {$ready}, last finished run ".($last === null ? 'never' : Carbon::parse($last)->toDateTimeString().' UTC');

        return DoctorRow::by($ready === 0 || $recent, 'fail', 'Queue', 'adssync worker', $value,
            'Jobs wait on adssync and no sync finished in 15 min: check the crm-adssync Supervisor program, or set CRM_ADS_SYNC_QUEUE=commercelong.');
    }
}
