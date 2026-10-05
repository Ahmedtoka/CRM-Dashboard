<?php

namespace App\Ads\Platforms\Data;

/** One account-day as the platform totals it (every ad status counted). $date is the account-timezone day. */
final readonly class AccountDailyTotal
{
    public function __construct(public string $date, public float $spend, public int $impressions, public float $purchases, public float $purchaseValue, public ?string $currency = null) {}
}
