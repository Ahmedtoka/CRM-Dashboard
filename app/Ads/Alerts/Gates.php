<?php

namespace App\Ads\Alerts;

use App\Models\Ad;
use App\Models\AdAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Spec 7.2 gates: stale data → no performance findings; Meta barely delivers (< 10 % of the ad set's spend) → not judged;
 * learning (first spend < 7 days ago) → only spend_no_result may speak (R-01); a performance Stop needs another healthy ad
 * in the ad set, else the action becomes «ضيف بديل الأول».
 */
final class Gates
{
    public const BARELY_SHARE = 0.10;

    public const LEARNING_DAYS = 7;

    /** @return array{ok: bool, last_ok_at: ?string, age_hours: ?float} */
    public function dataFresh(AdAccount $a, CarbonImmutable $now, AlertData $data): array
    {
        $at = $data->lastOkSyncAt($a->id);
        if ($at === null) {
            return ['ok' => false, 'last_ok_at' => null, 'age_hours' => null];
        }
        $hours = round($at->diffInMinutes($now, true) / 60, 1);

        return ['ok' => $hours <= (float) config('crm.ads.health.stale_after_hours', 3), 'last_ok_at' => $at->toIso8601String(), 'age_hours' => $hours];
    }

    public function barelyDelivered(float $adSpend, float $adSetSpend): bool
    {
        return $adSetSpend > 0 && $adSpend / $adSetSpend < self::BARELY_SHARE;
    }

    public function learning(?string $firstDay, string $today): bool
    {
        return $firstDay !== null
            && CarbonImmutable::parse($firstDay)->diffInDays(CarbonImmutable::parse($today), true) < self::LEARNING_DAYS;
    }

    /**
     * @param  Collection<int, Ad>  $liveAds
     * @param  list<int>  $unhealthyAdIds
     */
    public function hasHealthyAlternative(Ad $ad, Collection $liveAds, array $unhealthyAdIds): bool
    {
        if ($ad->ad_set_id === null) {
            return true; // no ad set known: never hold a Stop back
        }

        return $liveAds->contains(fn (Ad $o) => $o->id !== $ad->id && $o->ad_set_id === $ad->ad_set_id && ! in_array($o->id, $unhealthyAdIds, true));
    }
}
