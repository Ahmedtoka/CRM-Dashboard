<?php

namespace App\Ads\Sync;

use App\Ads\Audit\AdsAudit;
use App\Inbox\UserNotifier;
use App\Models\AdPlatformConnection;

/** Connection state that Meta can change under us: a dead token. */
final class ConnectionHealth
{
    /** A needs_reconnect connection is asked again (one probe) at most this often. */
    public const PROBE_EVERY_MINUTES = 60;

    /**
     * Compare-and-set to needs_reconnect. Only the call that makes the transition notifies and audits.
     *
     * @return bool whether this call made the transition
     */
    public static function markNeedsReconnect(AdPlatformConnection $c, string $scrubbedMessage): bool
    {
        $old = (string) AdPlatformConnection::whereKey($c->id)->value('status');
        $now = now();

        $changed = AdPlatformConnection::whereKey($c->id)->where('status', '<>', 'needs_reconnect')
            ->update(['status' => 'needs_reconnect', 'needs_reconnect_at' => $now, 'probed_at' => $now, 'last_error' => $scrubbedMessage]) === 1;

        if (! $changed) {
            // Already waiting for a new token: a failed probe only refreshes the timer and the message.
            AdPlatformConnection::whereKey($c->id)->update(['probed_at' => $now, 'last_error' => $scrubbedMessage]);
            $c->refresh();

            return false;
        }

        $c->refresh();
        app(UserNotifier::class)->notifyAdmins('ads.token_invalid', ['connection' => $c->name, 'connection_id' => $c->id]);
        AdsAudit::record('connection.needs_reconnect', $c, ['status' => $old], ['status' => 'needs_reconnect']);

        return true;
    }

    /**
     * A generic failure: status becomes error, except that a connection waiting for a new token keeps
     * needs_reconnect (only the message is stored), so the next dead-token answer does not notify again.
     */
    public static function markError(AdPlatformConnection $c, string $message): void
    {
        AdPlatformConnection::whereKey($c->id)->where('status', '<>', 'needs_reconnect')->update(['status' => 'error']);
        AdPlatformConnection::whereKey($c->id)->update(['last_error' => $message]);
        $c->refresh();
    }

    /**
     * May a sync call Meta for this connection now? A healthy connection always may; a needs_reconnect one only
     * once per hour: the caller that wins the claim is the probe.
     */
    public static function claimProbe(AdPlatformConnection $c): bool
    {
        if ((string) AdPlatformConnection::whereKey($c->id)->value('status') !== 'needs_reconnect') {
            return true;
        }

        return AdPlatformConnection::whereKey($c->id)->where('status', 'needs_reconnect')
            ->where(fn ($q) => $q->whereNull('probed_at')->orWhere('probed_at', '<=', now()->subMinutes(self::PROBE_EVERY_MINUTES)))
            ->update(['probed_at' => now()]) === 1;
    }
}
