<?php

namespace App\Ads\Control\Write;

use App\Ads\AdsSettings;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

/**
 * The global CRM writes kill switch (B9 part, R-26). Writes are on only when the deploy config (CRM_ADS_WRITES_ENABLED)
 * AND the runtime setting ads_settings.writes_enabled (changed by ads:writes) are both on. Stop is always exempt (2.1 #5).
 * Read from the database on every call: the cache never decides a write (2.1 #8).
 */
final class WriteSwitch
{
    public const SETTING = 'writes_enabled';

    public const CODE = 'writes_disabled';

    public static function enabled(): bool
    {
        return self::configEnabled() && self::settingEnabled();
    }

    public static function configEnabled(): bool
    {
        return config('crm.ads.write.enabled') === true;
    }

    public static function settingEnabled(): bool
    {
        return app(AdsSettings::class)->get(self::SETTING, true) !== false;
    }

    /** @param  string|null  $to  target status of a set_status write ('active'|'paused') */
    public static function allows(string $type, ?string $to = null): bool
    {
        return self::enabled() || ($type === 'set_status' && $to === 'paused');
    }

    /** @throws HttpResponseException 503 writes_disabled when the write is not allowed */
    public static function assertAllows(string $type, ?string $to = null): void
    {
        if (! self::allows($type, $to)) {
            throw new HttpResponseException(self::refusal());
        }
    }

    /** The 503 refusal body (stable refusal shape; errors.status keeps the old axios handling working). */
    public static function refusal(): JsonResponse
    {
        $message = __('ads.errors.'.self::CODE);

        return response()->json(['code' => self::CODE, 'message' => $message, 'details' => (object) [], 'errors' => ['status' => [$message]]], 503);
    }
}
