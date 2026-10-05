<?php

namespace App\Ads\Control;

use App\Models\AdAccount;

/**
 * Which ad accounts the CRM may write to: an active account whose ad_accounts.write_enabled is on (B1). The column
 * defaults to on for every account, existing and newly discovered (owner decision D3); the owner narrows it with
 * ads:writable. The slice-1 setting ads_settings.writable_account_ids is no longer read (migrated once into the column).
 *
 * Callers that load a partial account row must select is_active and write_enabled.
 */
final class WritableAccounts
{
    /** The slice-1 setting key, kept only as the rollback source for the old code. */
    public const LEGACY_KEY = 'writable_account_ids';

    public static function allows(AdAccount $a): bool
    {
        return (bool) $a->is_active && (bool) $a->write_enabled;
    }
}
