<?php

namespace App\Bot\Flow;

use Carbon\CarbonImmutable;

/**
 * Pure text substitution for owner script bodies (overnight refinement change
 * 1): `{time_greeting}` becomes "صباح الخير" for Africa/Cairo local time
 * 05:00-11:59, "مساء الخير" otherwise. Applied wherever a script body reaches
 * a customer (TurnRunner::bodies(), ReplyComposer's own first-reply greeting
 * fetch) before PriceGuard/PromiseGuard see the text, so the guards compare
 * against what was actually sent.
 */
final class ScriptPlaceholders
{
    private const TIMEZONE = 'Africa/Cairo';

    private const MORNING_START_MINUTE = 5 * 60; // 05:00

    private const MORNING_END_MINUTE = 12 * 60 - 1; // 11:59

    public function render(string $body): string
    {
        if (! str_contains($body, '{time_greeting}')) {
            return $body;
        }

        return str_replace('{time_greeting}', $this->timeGreeting(), $body);
    }

    private function timeGreeting(): string
    {
        $now = CarbonImmutable::now()->setTimezone(self::TIMEZONE);
        $minutesSinceMidnight = ((int) $now->format('H')) * 60 + (int) $now->format('i');

        return $minutesSinceMidnight >= self::MORNING_START_MINUTE && $minutesSinceMidnight <= self::MORNING_END_MINUTE
            ? 'صباح الخير'
            : 'مساء الخير';
    }
}
