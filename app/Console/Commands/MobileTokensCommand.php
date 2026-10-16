<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Mobile token housekeeping (spec D5). Without options it only reports how many API
 * tokens are legacy (no expiry — the old app), expiring, or already expired.
 * `--expire-legacy` gives every legacy token an expiry of now + the refresh window
 * (crm.mobile.token_refresh_days, 7): the new app refreshes into a 30-day token,
 * the old app is sent back to login once the grace ends.
 */
class MobileTokensCommand extends Command
{
    protected $signature = 'crm:mobile-tokens {--expire-legacy : Give tokens without an expiry a short grace expiry} {--dry-run : Report only, change nothing}';

    protected $description = 'Report mobile API tokens and optionally expire legacy (non-expiring) ones';

    public function handle(): int
    {
        $legacy = PersonalAccessToken::query()->whereNull('expires_at');
        $legacyCount = (clone $legacy)->count();
        $expiring = PersonalAccessToken::query()->where('expires_at', '>', now())->count();
        $expired = PersonalAccessToken::query()->where('expires_at', '<=', now())->count();

        $this->table(['legacy (no expiry)', 'expiring', 'expired'], [[$legacyCount, $expiring, $expired]]);

        if (! $this->option('expire-legacy')) {
            return self::SUCCESS;
        }

        $graceDays = (int) config('crm.mobile.token_refresh_days', 7);
        $until = now()->addDays($graceDays);

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$legacyCount} legacy token(s) would expire at {$until->toIso8601String()}.");

            return self::SUCCESS;
        }

        $updated = $legacy->update(['expires_at' => $until]);
        $this->info("{$updated} legacy token(s) now expire at {$until->toIso8601String()}.");

        return self::SUCCESS;
    }
}
