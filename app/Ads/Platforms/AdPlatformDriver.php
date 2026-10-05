<?php

namespace App\Ads\Platforms;

use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use Carbon\CarbonImmutable;

interface AdPlatformDriver
{
    /** @return list<Data\AccountInfo> */
    public function accounts(AdPlatformConnection $c): array;

    /** @return list<Data\AdRow> */
    public function ads(AdAccount $a): array;

    /** @return list<Data\DailyAdMetric> */
    public function dailyMetrics(AdAccount $a, CarbonImmutable $from, CarbonImmutable $to): array;

    /**
     * Account-level daily totals over [from, to], every ad status included (the control total). [] = no control
     * for this platform.
     *
     * @return list<Data\AccountDailyTotal>
     */
    public function accountDaily(AdAccount $a, CarbonImmutable $from, CarbonImmutable $to): array;

    /**
     * @param  list<string>  $adExternalIds
     * @return list<Data\CreativeMedia>
     */
    public function creativeMedia(AdAccount $a, array $adExternalIds): array;

    /** null = ok, otherwise the error message. */
    public function test(AdPlatformConnection $c): ?string;
}
