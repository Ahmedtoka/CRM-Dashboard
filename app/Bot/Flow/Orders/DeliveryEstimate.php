<?php

namespace App\Bot\Flow\Orders;

use App\Bot\Grounding\GovernorateMatcher;
use App\Models\Order;
use Carbon\CarbonImmutable;

/**
 * When an order should arrive (the delivery policy, docs/bot/levoile-reference.md
 * "Delivery Time"): Cairo / Giza / Alexandria 3-5 working days, other governorates
 * 5-7, counted from the day after the order was placed (Cairo time). Working days
 * skip Friday; there is no official-holiday calendar in the system, so holidays are
 * not skipped. The same rule as OrderStatusText::isDelayed: an order is late once
 * the last day of its window has passed.
 *
 * The governorate is the order's shipping address: its Shopify province code, else
 * GovernorateMatcher on its city, then its address line, else what the lookup kept.
 */
final class DeliveryEstimate
{
    /** Shopify / ISO province codes of the main cities. */
    public const MAIN_CITY_CODES = ['C', 'GZ', 'ALX'];

    /** [first, last] working day of the window. */
    public const MAIN_CITY_RANGE = [3, 5];

    public const OTHER_RANGE = [5, 7];

    /** Carbon dayOfWeek (0 = Sunday) → Arabic day name. */
    private const DAYS = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

    public function __construct(private readonly GovernorateMatcher $governorates) {}

    /** @param  string|null  $fallback  a governorate/city name kept earlier (OrderSnapshot::$governorate) */
    public function isMainCity(?Order $order, ?string $fallback = null): bool
    {
        $code = strtoupper(trim((string) $order?->shipping_province_code));

        if ($code !== '') {
            return in_array($code, self::MAIN_CITY_CODES, true);
        }

        foreach ([$order?->shipping_city, $order?->shipping_address] as $text) {
            if (($matched = $this->governorates->match(trim((string) $text))) !== null) {
                return in_array($matched, self::MAIN_CITY_CODES, true);
            }
        }

        return OrderStatusText::mainCity($fallback) !== null;
    }

    /** @return array{from: CarbonImmutable, to: CarbonImmutable} the first and last expected day (Cairo) */
    public function window(CarbonImmutable $placedAt, bool $mainCity): array
    {
        [$first, $last] = $mainCity ? self::MAIN_CITY_RANGE : self::OTHER_RANGE;
        $day = $placedAt->setTimezone(OrderStatusText::TIMEZONE)->startOfDay();

        return ['from' => self::addWorkingDays($day, $first), 'to' => self::addWorkingDays($day, $last)];
    }

    /** The window's last day has passed (Cairo). */
    public function overdue(array $window, CarbonImmutable $now): bool
    {
        return $now->setTimezone(OrderStatusText::TIMEZONE)->startOfDay()->greaterThan($window['to']);
    }

    /** "السبت 20/9 لحد الإثنين 22/9" */
    public static function text(array $window): string
    {
        return self::dayLabel($window['from']).' لحد '.self::dayLabel($window['to']);
    }

    /** "الإثنين 8/9" (Cairo) */
    public static function dayLabel(CarbonImmutable $day): string
    {
        $day = $day->setTimezone(OrderStatusText::TIMEZONE);

        return self::DAYS[$day->dayOfWeek].' '.$day->format('j/n');
    }

    /** The $n-th working day (Fridays skipped) after $day. */
    public static function addWorkingDays(CarbonImmutable $day, int $n): CarbonImmutable
    {
        $count = 0;

        while ($count < $n) {
            $day = $day->addDay();

            if (! $day->isFriday()) {
                $count++;
            }
        }

        return $day;
    }
}
