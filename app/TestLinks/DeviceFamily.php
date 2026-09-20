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
}
