<?php

namespace App\Bot;

use App\Models\BotSetting;
use Carbon\CarbonImmutable;

/**
 * The team's working hours (`bot_settings.working_hours` = {days: [0..6], from: "HH:MM",
 * to: "HH:MM"}, Africa/Cairo; Sunday is 0). Shared by the bot's hours gate and the
 * handover reply of the owner's flow 7 (2026-09-19), which names the next opening:
 * "النهارده الساعة 7 بالليل", "بكرة الساعة 10 الصبح", "يوم السبت الساعة 10:30 الصبح".
 */
final class WorkingHours
{
    public const TIMEZONE = 'Africa/Cairo';

    private const DAY_NAMES = ['الأحد', 'الإتنين', 'التلات', 'الأربع', 'الخميس', 'الجمعة', 'السبت'];

    /** Whether the owner set any hours (none = the team is there all the time). */
    public static function configured(BotSetting $settings): bool
    {
        $hours = $settings->working_hours;

        return is_array($hours) && (filled($hours['from'] ?? null) || filled($hours['to'] ?? null) || ! empty($hours['days']));
    }

    /** Inside the hours now (always, when none are set). A range like 22:00–02:00 crosses midnight. */
    public static function isOpen(BotSetting $settings, ?CarbonImmutable $at = null): bool
    {
        $hours = $settings->working_hours;

        if (empty($hours)) {
            return true;
        }

        $now = ($at ?? CarbonImmutable::now())->setTimezone(self::TIMEZONE);
        $days = self::days($hours);

        if (! in_array($now->dayOfWeek, $days, true)) {
            return false;
        }

        $current = $now->format('H:i');
        $from = self::time($hours['from'] ?? null) ?? '00:00';
        $to = self::time($hours['to'] ?? null) ?? '23:59';

        if ($from > $to) {
            return $current >= $from || $current <= $to;
        }

        return $current >= $from && $current <= $to;
    }

    /** The next time the team opens after $at (Cairo time), or null when no day is open. */
    public static function nextOpening(BotSetting $settings, ?CarbonImmutable $at = null): ?CarbonImmutable
    {
        $hours = $settings->working_hours;

        if (empty($hours)) {
            return null;
        }

        $now = ($at ?? CarbonImmutable::now())->setTimezone(self::TIMEZONE);
        $days = self::days($hours);
        [$h, $m] = array_map('intval', explode(':', self::time($hours['from'] ?? null) ?? '00:00'));

        for ($i = 0; $i <= 7; $i++) {
            $day = $now->startOfDay()->addDays($i);
            $opening = $day->setTime($h, $m);

            if (in_array($day->dayOfWeek, $days, true) && $opening->greaterThan($now)) {
                return $opening;
            }
        }

        return null;
    }

    /** "بكرة الساعة 10 الصبح" for the next opening, relative to $at. */
    public static function phrase(CarbonImmutable $opening, ?CarbonImmutable $at = null): string
    {
        $now = ($at ?? CarbonImmutable::now())->setTimezone(self::TIMEZONE);
        $opening = $opening->setTimezone(self::TIMEZONE);
        $days = (int) $now->startOfDay()->diffInDays($opening->startOfDay());

        $day = match (true) {
            $days <= 0 => 'النهارده',
            $days === 1 => 'بكرة',
            default => 'يوم '.self::DAY_NAMES[$opening->dayOfWeek],
        };

        return $day.' الساعة '.self::clock($opening);
    }

    /** "10 الصبح", "10:30 الصبح", "2 الضهر", "5 العصر", "8 بالليل". */
    public static function clock(CarbonImmutable $t): string
    {
        $hour = (int) $t->format('G');
        $twelve = $hour % 12 === 0 ? 12 : $hour % 12;
        $minutes = (int) $t->format('i');

        $period = match (true) {
            $hour >= 5 && $hour < 12 => 'الصبح',
            $hour >= 12 && $hour < 15 => 'الضهر',
            $hour >= 15 && $hour < 18 => 'العصر',
            default => 'بالليل',
        };

        return $twelve.($minutes > 0 ? ':'.str_pad((string) $minutes, 2, '0', STR_PAD_LEFT) : '').' '.$period;
    }

    /** @return list<int> */
    private static function days(array $hours): array
    {
        // Same rule as the bot's hours gate always had: no `days` key = every day.
        $days = $hours['days'] ?? range(0, 6);

        return is_array($days) ? array_values(array_map('intval', $days)) : range(0, 6);
    }

    private static function time(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{2}:\d{2}/', $value) === 1 ? substr($value, 0, 5) : null;
    }
}
