<?php

namespace App\Bot\Flow\Orders;

use App\Bot\ArabicNormalizer;
use Carbon\CarbonImmutable;

/**
 * Customer-facing status lines and the delivery-time policy (spec §3,
 * docs/bot/levoile-reference.md "Delivery Time"): Cairo / Giza / Alexandria
 * 3-5 working days, other governorates 5-7. An order is delayed once more
 * than 5 (main cities) or 7 working days have passed since it was placed.
 * Working days skip Friday only; official holidays are not counted.
 */
final class OrderStatusText
{
    public const TIMEZONE = 'Africa/Cairo';

    public const MAIN_CITY_DAYS = 5;

    public const OTHER_DAYS = 7;

    /** Normalized needle → canonical governorate name. */
    private const MAIN_CITIES = [
        'القاهره' => 'القاهرة', 'cairo' => 'القاهرة',
        'الجيزه' => 'الجيزة', 'جيزه' => 'الجيزة', 'giza' => 'الجيزة',
        'اسكندريه' => 'الإسكندرية', 'alex' => 'الإسكندرية',
    ];

    /** Staff-facing short status words per status key (case summaries); unknown keys read as confirmed. */
    public const SHORT_LABELS = [
        'confirmed' => 'اتأكد وجاري تجهيزه',
        'prepared' => 'اتجهز ومستني شركة الشحن',
        'shipped' => 'اتشحن',
        'on_the_way' => 'مع المندوب في الطريق',
        'delivered' => 'اتسلم',
        'cancelled' => 'اتلغى',
        'hold' => 'متوقف ومحتاج مراجعة',
        'returned' => 'رجع لينا (مرتجع)',
    ];

    /** The "📦 الحالة:" line of the tracking flow's status card (2026-09-19); unknown keys read as confirmed. */
    public const CARD_LINES = [
        'confirmed' => 'اتأكد وجاري تجهيزه',
        'prepared' => 'اتجهز وهيتسلم لشركة الشحن قريب',
        'shipped' => 'اتشحن ومع شركة الشحن',
        'on_the_way' => 'مع المندوب في الطريق ليكي',
        'delivered' => 'اتسلم',
        'cancelled' => 'اتلغى',
        'hold' => 'متوقف مؤقتًا والفريق بيراجعه',
        'returned' => 'رجع من شركة الشحن',
    ];

    public const CARD_FAILED_ATTEMPT = 'المندوب حاول يسلمه ومعرفش';

    public static function cardLine(string $key, bool $failedAttempt = false): string
    {
        if ($failedAttempt && ! in_array($key, ['delivered', 'cancelled'], true)) {
            return self::CARD_FAILED_ATTEMPT;
        }

        return self::CARD_LINES[$key] ?? self::CARD_LINES['confirmed'];
    }

    public static function shortLabel(string $key): string
    {
        return self::SHORT_LABELS[$key] ?? self::SHORT_LABELS['confirmed'];
    }

    public function line(OrderSnapshot $s): string
    {
        $n = $s->number;

        return match ($s->statusKey) {
            'prepared' => "الأوردر رقم {$n} اتجهز وهيتسلم لشركة الشحن قريب",
            'shipped' => "الأوردر رقم {$n} اتشحن ✨".($s->trackingUrl ? "\nتقدري تتابعيه من هنا: {$s->trackingUrl}" : ''),
            'on_the_way' => "الأوردر رقم {$n} مع المندوب في الطريق ليكي 🚚",
            'delivered' => "الأوردر رقم {$n} اتسلم، لو في أي مشكلة بلغيني 🌸",
            'cancelled' => "الأوردر رقم {$n} اتلغى",
            'hold', 'returned' => "هراجع الأوردر رقم {$n} مع الفريق وهرد على حضرتك",
            default => "الأوردر رقم {$n} اتأكد وجاري تجهيزه 🌸",
        };
    }

    public function needsAgent(OrderSnapshot $s): bool
    {
        return in_array($s->statusKey, ['hold', 'returned'], true);
    }

    public function isDelayed(OrderSnapshot $s, CarbonImmutable $now): bool
    {
        // Finished orders are not late; hold/returned already need a person for their own reason.
        if (in_array($s->statusKey, ['delivered', 'cancelled', 'hold', 'returned'], true)) {
            return false;
        }

        $limit = self::mainCity($s->governorate) !== null ? self::MAIN_CITY_DAYS : self::OTHER_DAYS;

        return $this->workingDaysSince($s->placedAt, $now, $limit + 1) > $limit;
    }

    /** Canonical Cairo / Giza / Alexandria name when the text names one of them, else null. */
    public static function mainCity(?string $text): ?string
    {
        $t = (new ArabicNormalizer)->normalize(trim((string) $text));

        if ($t === '') {
            return null;
        }

        foreach (self::MAIN_CITIES as $needle => $name) {
            if (str_contains($t, $needle)) {
                return $name;
            }
        }

        return null;
    }

    /** Calendar days after the day it was placed up to today, Fridays excluded; stops counting at $cap. */
    private function workingDaysSince(CarbonImmutable $placedAt, CarbonImmutable $now, int $cap): int
    {
        $day = $placedAt->setTimezone(self::TIMEZONE)->startOfDay()->addDay();
        $end = $now->setTimezone(self::TIMEZONE)->startOfDay();
        $count = 0;

        while ($day->lessThanOrEqualTo($end) && $count < $cap) {
            if (! $day->isFriday()) {
                $count++;
            }

            $day = $day->addDay();
        }

        return $count;
    }
}
