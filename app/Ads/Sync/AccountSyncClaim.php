<?php

namespace App\Ads\Sync;

use App\Models\AdAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionProperty;

/**
 * One claim per ad account for every sync kind (hourly, deep, backfill, inline command), held in MariaDB so a cache
 * flush can never let two syncs of one account run together (A5, F-052; roadmap 2.1 #8). A claim expires on its own
 * after its TTL, so a killed worker frees the account without help.
 */
final class AccountSyncClaim
{
    /** @return string|null the claim key, or null while another sync holds an unexpired claim */
    public static function acquire(AdAccount $a, int $ttlSeconds): ?string
    {
        $key = (string) Str::uuid();
        $now = now();
        $won = DB::table('ad_accounts')->where('id', $a->id)
            ->where(fn ($q) => $q->whereNull('sync_claimed_until')->orWhere('sync_claimed_until', '<', $now))
            ->update(['sync_claim_key' => $key, 'sync_claimed_until' => $now->copy()->addSeconds($ttlSeconds)]);

        return $won === 1 ? $key : null;
    }

    /** Pushes the expiry of a claim this caller still holds (a long backfill, chunk by chunk). */
    public static function extend(AdAccount $a, string $key, int $ttlSeconds): void
    {
        DB::table('ad_accounts')->where('id', $a->id)->where('sync_claim_key', $key)
            ->update(['sync_claimed_until' => now()->addSeconds($ttlSeconds)]);
    }

    /** Clears the claim only when $key still owns it. */
    public static function release(AdAccount $a, string $key): void
    {
        DB::table('ad_accounts')->where('id', $a->id)->where('sync_claim_key', $key)
            ->update(['sync_claim_key' => null, 'sync_claimed_until' => null]);
    }

    /** SyncAdAccount::$timeout + 60 s: a claim outlives the longest job by a minute. */
    public static function ttl(): int
    {
        return (int) (new ReflectionProperty(SyncAdAccount::class, 'timeout'))->getDefaultValue() + 60;
    }
}
