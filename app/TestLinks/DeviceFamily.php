<?php

namespace App\TestLinks;

/**
 * The user-agent family only (design 2026-09-21 §4): enough for the owner to see
 * «إتعمل على أندرويد» without keeping a fingerprint of the tester's phone.
 */
final class DeviceFamily
{
    public static function of(?string $userAgent): string
    {
        $ua = strtolower((string) $userAgent);

        return match (true) {
            $ua === '' => 'unknown',
            str_contains($ua, 'ipad') => 'iPad',
            str_contains($ua, 'iphone') || str_contains($ua, 'ipod') => 'iPhone',
            str_contains($ua, 'android') => str_contains($ua, 'mobile') ? 'Android' : 'Android tablet',
            str_contains($ua, 'windows phone') => 'Windows Phone',
            str_contains($ua, 'macintosh') || str_contains($ua, 'mac os') => 'Mac',
            str_contains($ua, 'windows') => 'Windows',
            str_contains($ua, 'linux') => 'Linux',
            default => 'unknown',
        };
    }

    /**
     * The stored family rendered in the viewer's language. `of()` deliberately
     * keeps returning a stable code because its result is PERSISTED on
     * `bot_test_sessions.device_family` by the visitor's own request — only the
     * two prose values are translated here; the rest are brand words that read
     * the same in both locales.
     */
    public static function label(?string $stored): string
    {
        $value = (string) ($stored ?: 'unknown');

        return match ($value) {
            'unknown' => __('labels.device.unknown'),
            'Android tablet' => __('labels.device.android_tablet'),
            default => $value,
        };
    }
}
