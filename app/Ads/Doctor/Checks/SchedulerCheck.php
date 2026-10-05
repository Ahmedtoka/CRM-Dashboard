<?php

namespace App\Ads\Doctor\Checks;

use App\Ads\Doctor\DoctorRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class SchedulerCheck extends DoctorCheck
{
    protected function section(): string
    {
        return 'Scheduler';
    }

    public function run(): array
    {
        $rows = [];
        $beat = Cache::get('crm:scheduler_heartbeat');
        if (! $beat) {
            $rows[] = DoctorRow::fail('Scheduler', 'heartbeat', 'never seen', 'No scheduler heartbeat in the cache: the cron job is not running (or the cache was just cleared).');
        } else {
            $age = max(0, (int) CarbonImmutable::parse((string) $beat)->diffInSeconds(now(), true));
            $status = $age > 300 ? 'fail' : ($age > 120 ? 'warn' : 'ok');
            $rows[] = new DoctorRow('Scheduler', 'heartbeat', $status, "{$age} s ago", $status === 'ok' ? '' : 'The scheduler last ran more than 2 minutes ago.');
        }

        $log = storage_path('logs/ads-schedule.log');
        if (! is_file($log)) {
            $rows[] = DoctorRow::warn('Scheduler', 'ads-schedule.log', 'missing', 'No output log yet: the scheduled ads commands have not run since the log was added.');
        } else {
            $rows[] = DoctorRow::ok('Scheduler', 'ads-schedule.log modified', CarbonImmutable::createFromTimestamp((int) filemtime($log))->toIso8601String());
            foreach ($this->tail($log, 5) as $i => $line) {
                $rows[] = DoctorRow::ok('Scheduler', 'log line '.($i + 1), self::clean($line, 200));
            }
        }

        return $rows;
    }

    /** @return list<string> */
    private function tail(string $file, int $n): array
    {
        $size = (int) filesize($file);
        $fh = fopen($file, 'rb');
        if ($fh === false) {
            return [];
        }
        fseek($fh, max(0, $size - 8192));
        $chunk = (string) stream_get_contents($fh);
        fclose($fh);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $chunk)), fn ($l) => $l !== ''));

        return array_slice($lines, -$n);
    }
}
