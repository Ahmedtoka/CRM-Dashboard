<?php

namespace App\Ads\Control\Write;

use App\Models\AdWriteAction;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The named limiter of the write routes (throttle:ads-writes). Its keys are its own (per user and per Stop / Run
 * group), so search, the queue desk or AI captions can never use up a write budget. A Stop stops spend, so it gets a
 * far higher limit than a Run and is never throttled out by Runs (2.1 rule 3 spirit).
 */
final class WriteRateLimits
{
    public const NAME = 'ads-writes';

    public static function register(): void
    {
        RateLimiter::for(self::NAME, function (Request $request) {
            $who = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return self::isStop($request)
                ? Limit::perMinute((int) config('crm.ads.write.stop_per_minute', 120))->by(self::NAME.':stop:'.$who)
                : Limit::perMinute((int) config('crm.ads.write.run_per_minute', 30))->by(self::NAME.':run:'.$who);
        });
    }

    /** Propose with to=paused, confirm of a Stop, or rollback of a Run (its inverse is a Stop). */
    public static function isStop(Request $request): bool
    {
        $route = $request->route();
        $name = $route?->getName();
        if ($name === 'ads.write-actions.store') {
            return $request->input('params.to') === 'paused';
        }
        $id = $route?->parameter('action');
        if (! is_string($id) || $id === '') {
            return false;
        }
        $to = AdWriteAction::where('public_id', $id)->value('to_status');

        return match ($name) {
            'ads.write-actions.confirm' => $to === 'paused',
            'ads.write-actions.rollback' => $to === 'active',
            default => false,
        };
    }
}
