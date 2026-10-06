<?php

namespace App\Ads\Launch;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * One HEAD request (5 s, redirects followed), remembered 10 minutes per URL, failures too (a down store must not cost
 * 5 s per launch on every list). A failure is a warning, never a block.
 */
final class HttpLandingProbe implements LandingProbe
{
    public const FAILED = -1;

    public function status(string $url, bool $live = true): ?int
    {
        $cached = Cache::get(self::key($url));
        if (is_int($cached)) {
            return $cached === self::FAILED ? null : $cached;
        }
        if (! $live) {
            return null;
        }
        try {
            $status = Http::timeout(5)->withOptions(['allow_redirects' => true])->head($url)->status();
        } catch (Throwable) {
            $status = self::FAILED;
        }
        Cache::put(self::key($url), $status, now()->addMinutes(10));

        return $status === self::FAILED ? null : $status;
    }

    public function known(string $url): bool
    {
        return is_int(Cache::get(self::key($url)));
    }

    private static function key(string $url): string
    {
        return 'ads-landing:'.sha1($url);
    }
}
