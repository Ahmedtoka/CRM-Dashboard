<?php

namespace App\Orders;

/** The platform's ad manager link for one ad (Meta only today), as resources/js/lib/ads.ts adsManagerUrl builds it. */
final class AdsManagerLink
{
    public static function for(?string $platform, ?string $externalId): ?string
    {
        if ($platform !== 'meta' || $externalId === null || $externalId === '') {
            return null;
        }

        return 'https://www.facebook.com/adsmanager/manage/ads?selected_ad_ids='.rawurlencode($externalId);
    }
}
