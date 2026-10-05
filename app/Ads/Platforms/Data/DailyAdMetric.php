<?php

namespace App\Ads\Platforms\Data;

final readonly class DailyAdMetric
{
    public function __construct(public string $adExternalId, public string $date, public float $spend, public int $impressions, public int $clicks, public int $reach, public float $purchases, public float $purchaseValue, public ?string $adName = null, public ?string $campaignId = null, public ?string $campaignName = null, public ?string $adSetId = null, public ?string $adSetName = null, public int $linkClicks = 0, public int $msgConversations = 0) {}
}
