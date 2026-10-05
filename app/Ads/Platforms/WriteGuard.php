<?php

namespace App\Ads\Platforms;

use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Ads\Platforms\Meta\MetaAdsWriter;
use App\Ads\Platforms\TikTok\TikTokAdsWriter;
use App\Models\AdAccount;

/**
 * Last check before a platform write: a fake writer must never "succeed" in production (nothing would reach the platform),
 * and a live writer outside production only touches accounts listed in crm.ads.write_sandbox_accounts.
 */
final class WriteGuard
{
    /** @throws WriteRefused */
    public static function check(AdAccount $a, AdPlatformWriter $w): void
    {
        if (app()->environment('production')) {
            if ($w instanceof FakeAdsDriver) {
                throw new WriteRefused('fake_writer_in_production');
            }

            return;
        }

        if (($w instanceof MetaAdsWriter || $w instanceof TikTokAdsWriter)
            && ! in_array((string) $a->external_id, (array) config('crm.ads.write_sandbox_accounts', []), true)) {
            throw new WriteRefused('sandbox_only');
        }
    }
}
