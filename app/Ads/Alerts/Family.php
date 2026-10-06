<?php

namespace App\Ads\Alerts;

/**
 * What an ad is for, from its campaign objective (A1: the C1 ad-set columns are not stored yet). Engagement and lead
 * campaigns that start conversations are judged as Messages.
 */
final class Family
{
    public const SALES = 'SALES';

    public const MESSAGES = 'MESSAGES';

    public const TRAFFIC = 'TRAFFIC';

    public const OTHER = 'OTHER';

    private const SALES_OBJECTIVES = ['OUTCOME_SALES', 'CONVERSIONS', 'PRODUCT_CATALOG_SALES'];

    private const MESSAGE_OBJECTIVES = ['MESSAGES'];

    private const CHAT_CAPABLE_OBJECTIVES = ['OUTCOME_ENGAGEMENT', 'OUTCOME_LEADS', 'LEAD_GENERATION', 'POST_ENGAGEMENT'];

    private const TRAFFIC_OBJECTIVES = ['OUTCOME_TRAFFIC', 'LINK_CLICKS'];

    public static function of(?string $objective, int|float $msgConversations = 0): string
    {
        $o = strtoupper(trim((string) $objective));

        return match (true) {
            in_array($o, self::SALES_OBJECTIVES, true) => self::SALES,
            in_array($o, self::MESSAGE_OBJECTIVES, true) => self::MESSAGES,
            in_array($o, self::CHAT_CAPABLE_OBJECTIVES, true) && $msgConversations > 0 => self::MESSAGES,
            in_array($o, self::TRAFFIC_OBJECTIVES, true) => self::TRAFFIC,
            default => self::OTHER,
        };
    }
}
