<?php

namespace App\Ads\Launch;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/** One HEAD request (5 s, redirects followed), remembered 10 minutes per URL. A failure is a warning, never a block. */
final class HttpLandingProbe implements LandingProbe
{
    public function status(string $url): ?int
    {
        $key = 'ads-landing:'.sha1($url);
        $cached = Cache::get($key);
        if (is_int($cached)) {
            return $cached;
        }
        try {
            $status = Http::timeout(5)->withOptions(['allow_redirects' => true])->head($url)->status();
        } catch (Throwable) {
            return null;
        }
        Cache::put($key, $status, now()->addMinutes(10));

        return $status;
    }
}
