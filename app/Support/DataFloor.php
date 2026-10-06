<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The first day (Cairo calendar day) the CRM keeps any synced data for: `crm.data_floor` (F3, default 2026-10-01).
 * Ads sync/backfill never request an earlier day; Shopify import/sync never stores an order created before it.
 */
final class DataFloor
{
    public const TIMEZONE = 'Africa/Cairo';

    public static function start(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) config('crm.data_floor', '2026-10-01'), self::TIMEZONE)->startOfDay();
    }

    /** True when the moment is before the floor's first instant; null/unparseable is never "before". */
    public static function isBefore(DateTimeInterface|string|null $moment): bool
    {
        if ($moment === null || $moment === '') {
            return false;
        }

        try {
            $time = $moment instanceof DateTimeInterface ? CarbonImmutable::instance($moment) : CarbonImmutable::parse($moment);
        } catch (\Throwable) {
            return false;
        }

        return $time->lessThan(self::start());
    }

    /** The later of the two moments: a since/from date clamped to the floor. */
    public static function clamp(CarbonImmutable $since): CarbonImmutable
    {
        $start = self::start();

        return $since->lessThan($start) ? $start->setTimezone($since->getTimezone()) : $since;
    }
}
