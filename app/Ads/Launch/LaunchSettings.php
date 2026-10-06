<?php

namespace App\Ads\Launch;

use App\Ads\AdsSettings;

/** Launch settings in ads_settings (owner numbers, defaults per D7 and R section 3). */
final class LaunchSettings
{
    public const EXPIRY_KEY = 'launch.expiry_days';

    public const LOW_STOCK_KEY = 'launch.low_stock_units';

    public function __construct(private readonly AdsSettings $settings) {}

    public function expiryDays(): int
    {
        return max(1, (int) $this->settings->get(self::EXPIRY_KEY, 7));
    }

    public function lowStockUnits(): int
    {
        return max(0, (int) $this->settings->get(self::LOW_STOCK_KEY, 10));
    }
}
