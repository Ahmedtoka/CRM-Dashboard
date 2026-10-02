<?php

namespace App\Ads\Platforms;

use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Ads\Platforms\Meta\MetaAdsDriver;

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
            // TikTok / Google live drivers arrive in a later release.
            default => throw new AdsApiException($p->label().' live driver is not available yet.'),
        };
    }
}
