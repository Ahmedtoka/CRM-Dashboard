<?php

namespace App\Ads\Doctor\Checks;

use App\Ads\Doctor\DoctorRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FailedJobsCheck extends DoctorCheck
{
    protected function section(): string
    {
        return 'Failed jobs';
    }

    public function run(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [DoctorRow::skip('Failed jobs', 'failed_jobs', 'table missing')];
        }

        $rows = [];
        foreach (['SyncAdAccount', 'PublishAd'] as $job) {
            $q = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDays(7))->where('payload', 'like', "%{$job}%");
            $count = (clone $q)->count();
            if ($count === 0) {
                $rows[] = DoctorRow::ok('Failed jobs', "{$job}, 7 days", '0');

                continue;
            }
            $latest = (string) (clone $q)->orderByDesc('failed_at')->orderByDesc('id')->value('exception');
            $first = strtok($latest, "\n") ?: '';
            $rows[] = DoctorRow::warn('Failed jobs', "{$job}, 7 days", (string) $count, 'Newest: '.self::clean($first, 200));
        }

        return $rows;
    }
}
