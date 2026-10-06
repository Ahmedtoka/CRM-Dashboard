<?php

namespace App\Ads\Sync;

use App\Support\DataFloor;
use Carbon\CarbonImmutable;

/**
 * The first day the CRM keeps ads history for (Cairo calendar day): the later of `crm.data_floor` (F3) and the
 * optional ads-only `crm.ads.history_start`.
 * No platform request may ask for an earlier date and no ads row older than it is written.
 */
final class HistoryWindow
{
    public const TIMEZONE = 'Africa/Cairo';

    public static function start(): CarbonImmutable
    {
        $floor = DataFloor::start();
        $raw = config('crm.ads.history_start');
        if (! is_string($raw) || trim($raw) === '') {
            return $floor;
        }
        $own = CarbonImmutable::parse($raw, self::TIMEZONE)->startOfDay();

        return $own->greaterThan($floor) ? $own : $floor;
    }

    /** Dates are Y-m-d strings: true when the day is before the history start. */
    public static function isBeforeStart(string $date): bool
    {
        return substr($date, 0, 10) < self::start()->toDateString();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null null when the whole window is before the start
     */
    public static function clamp(CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        $start = self::start();
        if ($to->toDateString() < $start->toDateString()) {
            return null;
        }
        if ($from->toDateString() < $start->toDateString()) {
            $from = $start;
        }

        return [$from, $to];
    }

    /** Days from the history start to $today, both included (0 when today is before the start). */
    public static function daysFromStart(CarbonImmutable $today): int
    {
        $a = self::start()->toDateString();
        $b = $today->toDateString();
        if ($b < $a) {
            return 0;
        }

        return (int) CarbonImmutable::parse($a, self::TIMEZONE)->diffInDays(CarbonImmutable::parse($b, self::TIMEZONE)) + 1;
    }
}
