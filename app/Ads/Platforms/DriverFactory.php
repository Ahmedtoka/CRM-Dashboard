<?php

namespace App\Ads\Platforms;

use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Ads\Platforms\Google\GoogleAdsDriver;
use App\Ads\Platforms\Meta\MetaAdsDriver;
use App\Ads\Platforms\TikTok\TikTokAdsDriver;

final class DriverFactory
{
    public function for(AdPlatform $p): AdPlatformDriver
    {
        // Strict: only the exact value 'live' reaches a real platform.
        $live = config('crm.ads.drivers.'.$p->value, 'fake') === 'live';

        if (! $live) {
            return app(FakeAdsDriver::class);
        }

        return match ($p) {
            AdPlatform::Meta => app(MetaAdsDriver::class),
            AdPlatform::Tiktok => app(TikTokAdsDriver::class),
            AdPlatform::Google => app(GoogleAdsDriver::class),
        };
    }
}
