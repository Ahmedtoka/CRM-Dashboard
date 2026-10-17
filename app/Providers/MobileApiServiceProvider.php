<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Named rate limiters for the mobile API (review 01, I1). A bare `throttle:N,1` keys on
 * the user id (or the IP for guests) only, so every such route shares one counter:
 * image fetches from the office NAT would lock out login, queue polling would lock out
 * refresh. Each limiter here has its own name, so its own counter.
 */
class MobileApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Login: per email + IP, so one moderator's typos never block a colleague on the same Wi-Fi.
        // Plus a per-IP cap so one address cannot spray many accounts (review 03, R1).
        RateLimiter::for('mobile-login', fn (Request $r) => [
            Limit::perMinute(10)->by('e:'.mb_strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perMinute(30)->by('ip:'.$r->ip()),
        ]);

        RateLimiter::for('mobile-refresh', fn (Request $r) => Limit::perMinute(10)->by(self::who($r)));
        RateLimiter::for('mobile-devices-test', fn (Request $r) => Limit::perMinute(5)->by(self::who($r)));
        RateLimiter::for('mobile-queue', fn (Request $r) => Limit::perMinute(60)->by(self::who($r)));
        RateLimiter::for('mobile-search', fn (Request $r) => Limit::perMinute(60)->by(self::who($r)));

        // Signed media: no user (no session), and a whole office shares one IP — generous.
        RateLimiter::for('mobile-media', fn (Request $r) => Limit::perMinute(1200)->by((string) $r->ip()));
    }

    private static function who(Request $r): string
    {
        return $r->user() ? 'u:'.$r->user()->getAuthIdentifier() : 'ip:'.$r->ip();
    }
}
