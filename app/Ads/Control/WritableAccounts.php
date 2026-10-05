<?php

namespace App\Ads\Control;

use App\Ads\AdsSettings;
use App\Models\AdAccount;

/**
 * Which ad accounts the CRM may write to. Setting ads_settings.writable_account_ids absent or null = every active account
 * (the default, D3); a list of external ids narrows it to those accounts (still active).
 */
final class WritableAccounts
{
    public const KEY = 'writable_account_ids';

    public static function allows(AdAccount $a): bool
    {
        if (! $a->is_active) {
            return false;
        }
        $list = self::list();

        return $list === null || in_array((string) $a->external_id, $list, true);
    }

    /** @return list<string>|null null = every active account */
    public static function list(): ?array
    {
        $v = app(AdsSettings::class)->get(self::KEY);

        return is_array($v) ? array_values(array_map('strval', $v)) : null;
    }

    /** @param  list<string>|null  $ids  null clears the override */
    public static function set(?array $ids): void
    {
        app(AdsSettings::class)->set(self::KEY, $ids === null ? null : array_values(array_unique($ids)));
    }
}
