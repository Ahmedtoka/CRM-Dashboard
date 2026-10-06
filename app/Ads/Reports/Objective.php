<?php

namespace App\Ads\Reports;

/**
 * What an ad is judged on (U 3.1): Messages ads by chats then real orders, Sales ads by purchases and real orders,
 * Traffic by clicks. A Sales-objective campaign that started Messenger conversations is a Messages ad (Le Voile sells
 * through chat; no destination field is stored yet, A4).
 */
final class Objective
{
    public const MESSAGES = 'messages';

    public const SALES = 'sales';

    public const TRAFFIC = 'traffic';

    public const OTHER = 'other';

    public const FAMILIES = [self::MESSAGES, self::SALES, self::TRAFFIC];

    private const MAP = [
        self::MESSAGES => ['MESSAGES', 'OUTCOME_ENGAGEMENT'],
        self::SALES => ['OUTCOME_SALES', 'CONVERSIONS', 'PRODUCT_CATALOG_SALES'],
        self::TRAFFIC => ['OUTCOME_TRAFFIC', 'LINK_CLICKS'],
    ];

    public static function family(?string $objective, int $conversations = 0): string
    {
        $o = strtoupper((string) $objective);
        if (in_array($o, self::MAP[self::MESSAGES], true) || $conversations > 0) {
            return self::MESSAGES;
        }
        foreach ([self::SALES, self::TRAFFIC] as $family) {
            if (in_array($o, self::MAP[$family], true)) {
                return $family;
            }
        }

        return self::OTHER;
    }

    /** @return list<string> */
    public static function values(string $family): array
    {
        return self::MAP[$family] ?? [];
    }
}
