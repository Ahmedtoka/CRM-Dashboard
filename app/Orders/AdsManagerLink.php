<?php

namespace App\Orders;

/**
 * The platform's ad manager link for one ad (Meta only today), with the ad account (`act=`, without the `act_`
 * prefix) when known, as resources/js/lib/ads.ts adsManagerUrl builds it.
 */
final class AdsManagerLink
{
    public static function for(?string $platform, ?string $externalId, ?string $accountExternalId = null): ?string
    {
        if ($platform !== 'meta' || $externalId === null || $externalId === '') {
            return null;
        }

        $act = preg_replace('/^act_/', '', (string) $accountExternalId);

        return 'https://www.facebook.com/adsmanager/manage/ads?'
            .($act !== '' && $act !== null ? 'act='.rawurlencode($act).'&' : '')
            .'selected_ad_ids='.rawurlencode($externalId);
    }
}
