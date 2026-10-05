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
     * Account-level daily totals over [from, to], every ad status included (the control total).
     * null = this platform has no control; [] = the control answered and no day had delivery (every day totals 0).
     *
     * @return list<Data\AccountDailyTotal>|null
     */
    public function accountDaily(AdAccount $a, CarbonImmutable $from, CarbonImmutable $to): ?array;

    /**
     * Light status lists for the nightly sweep: the ads in every status the full ad list leaves out (so the full
     * list plus this one cover every status), and every campaign with its own and effective status.
     * A list is null when it is not available (no sweep on this platform, or that call failed: see warnings);
     * GONE is only ever marked when 'ads' is a list.
     *
     * @return array{ads: array<string, array{status:?string, effective_status:?string}>|null, campaigns: array<string, array{name:?string, status:?string, effective_status:?string, objective:?string}>|null, warnings?: list<string>}
     */
    public function statuses(AdAccount $a): array;

    /**
     * Light campaign list for the hourly run (id, status, effective status of every campaign), so the active-campaign
     * scope stays about an hour fresh while the full ad list is read nightly only. null = not available on this platform.
     *
     * @return array<string, array{status:?string, effective_status:?string}>|null
     */
    public function campaignStatuses(AdAccount $a): ?array;

    /**
     * @param  list<string>  $adExternalIds
     * @return list<Data\CreativeMedia>
     */
    public function creativeMedia(AdAccount $a, array $adExternalIds): array;

    /** null = ok, otherwise the error message. */
    public function test(AdPlatformConnection $c): ?string;
}
