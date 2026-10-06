<?php

namespace App\Ads\Control\Write;

use Illuminate\Http\Request;

/** Run (and approve, S1) require a password typed in the last 15 minutes (R-31). Stop never does. */
final class RecentPassword
{
    public const SESSION_KEY = 'auth.password_confirmed_at';

    public static function window(): int
    {
        return max(0, (int) config('crm.ads.write.run_reauth_seconds', 900));
    }

    public static function fresh(Request $r): bool
    {
        $window = self::window();
        if ($window === 0) {
            return true;
        }
        $at = (int) ($r->hasSession() ? $r->session()->get(self::SESSION_KEY, 0) : 0);

        return $at > 0 && time() - $at <= $window;
    }
}
