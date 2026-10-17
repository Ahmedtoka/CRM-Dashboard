<?php

namespace App\Shopify\App;

use App\Inbox\UserNotifier;
use App\Shopify\Client\ShopifyException;
use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Connection\ShopifyIntegration;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The access token every Shopify call uses (shopify-app.md §2.3). A manual connection's token never
 * expires and is returned as stored. An app token (expiring offline token, one hour) is refreshed
 * when less than REFRESH_MARGIN_SECONDS are left, under one cache lock per store that the token
 * exchange takes too: Shopify forbids an exchange and a refresh for the same store at the same
 * time (each invalidates the other's result). Inside the lock the row is read again, so a process
 * that waited uses the token the first one stored instead of refreshing a second time.
 *
 * A refused refresh (the refresh token expired or was revoked) marks the store broken and tells
 * the admins to open the app from Shopify admin, which runs a new token exchange.
 */
final class ShopifyAccessTokens
{
    public const REFRESH_MARGIN_SECONDS = 300;

    private const LOCK_SECONDS = 30;

    private int $lockWaitSeconds = 15;

    public function __construct(
        private readonly ShopifyOAuth $oauth,
        private readonly ShopifyApps $apps,
        private readonly IntegrationRepository $integrations,
        private readonly UserNotifier $notifier,
    ) {}

    public static function lockKey(string $shop): string
    {
        return 'shopify:token:'.strtolower($shop);
    }

    /** A copy that waits at most $seconds for the store's token lock. */
    public function withLockWait(int $seconds): self
    {
        $copy = clone $this;
        $copy->lockWaitSeconds = max(0, $seconds);

        return $copy;
    }

    /**
     * The token to call Shopify with now.
     *
     * @param  string|null  $rejected  a token Shopify just answered 401 to: refreshed even if not near expiry,
     *                                 unless another process already replaced it
     *
     * @throws ShopifyException
     */
    public function token(ShopifyIntegration $integration, ?string $rejected = null): string
    {
        if (! $integration->usesApp() || ($integration->access_token_expires_at === null && $rejected === null)) {
            return $this->present($integration);
        }

        if ($rejected === null && ! $this->expiresSoon($integration)) {
            return $this->present($integration);
        }

        return $this->locked($integration->shop_domain, function () use ($integration, $rejected) {
            $fresh = $integration->fresh() ?? $integration;
            $replaced = $rejected !== null && $fresh->access_token !== $rejected;

            // Rolled back or disconnected while this process waited: whatever is stored now is what to use.
            if (! $fresh->usesApp()) {
                $integration->setRawAttributes($fresh->getAttributes(), true);

                return $this->present($fresh);
            }

            if (filled($fresh->access_token) && ($replaced || ($rejected === null && ! $this->expiresSoon($fresh)))) {
                $integration->setRawAttributes($fresh->getAttributes(), true);

                return (string) $fresh->access_token;
            }

            $this->refresh($fresh);
            $integration->setRawAttributes($fresh->getAttributes(), true);

            return (string) $fresh->access_token;
        });
    }

    /** Refreshes now if the app token has less than $seconds left (the scheduled keep-alive). */
    public function refreshIfExpiringWithin(ShopifyIntegration $integration, int $seconds): bool
    {
        if (! $integration->usesApp() || $integration->access_token_expires_at === null
            || $integration->access_token_expires_at->greaterThan(now()->addSeconds($seconds))) {
            return false;
        }

        return $this->locked($integration->shop_domain, function () use ($integration, $seconds) {
            $fresh = $integration->fresh() ?? $integration;

            if (! $fresh->usesApp() || $fresh->status !== 'connected'
                || ($fresh->access_token_expires_at !== null && $fresh->access_token_expires_at->greaterThan(now()->addSeconds($seconds)))) {
                return false;
            }

            $this->refresh($fresh);

            return true;
        });
    }

    /**
     * Token exchange and storing its result in ONE hold of the store's lock (review M1): a refresh
     * can never read the row between the exchange and the save, and two exchanges never save out
     * of order. $store persists the token set (InstallShopifyApp).
     *
     * @template T
     *
     * @param  callable(TokenSet): T  $store
     * @return T
     *
     * @throws ShopifyException
     */
    public function exchangeAndStore(VerifiedIdToken $token, string $idToken, callable $store): mixed
    {
        return $this->locked($token->shop, fn () => $store($this->oauth->exchange($token->app, $token->shop, $idToken)));
    }

    /**
     * @template T
     *
     * Runs $callback holding the store's token lock. Anything else that rewrites the token columns
     * (rollback, disconnect) goes through here so a refresh in flight cannot overwrite it (review H1).
     * The lock is not re-entrant: never call token()/refresh from inside $callback.
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function locked(string $shop, callable $callback): mixed
    {
        try {
            return Cache::lock(self::lockKey($shop), self::LOCK_SECONDS)->block($this->lockWaitSeconds, $callback);
        } catch (LockTimeoutException) {
            throw new ShopifyException('transport', 'Another Shopify token exchange or refresh is still running for this store.');
        }
    }

    private function refresh(ShopifyIntegration $integration): void
    {
        $app = $this->apps->byClientId($integration->app_client_id);

        if ($app === null) {
            $this->fail($integration, 'The Shopify app this store was installed with is not configured (SHOPIFY_APP_CLIENT_ID / SECRET).');
        }

        if (blank($integration->refresh_token)
            || ($integration->refresh_token_expires_at !== null && $integration->refresh_token_expires_at->isPast())) {
            $this->fail($integration, 'The Shopify app token expired: open the app from Shopify admin to reconnect.');
        }

        try {
            $set = $this->oauth->refresh($app, $integration->shop_domain, (string) $integration->refresh_token);
        } catch (ShopifyException $e) {
            if ($e->kind === 'auth') {
                $this->fail($integration, 'Shopify refused the app token refresh: open the app from Shopify admin to reconnect.');
            }

            throw $e;
        }

        DB::transaction(fn () => $integration->forceFill([
            'access_token' => $set->accessToken,
            'access_token_expires_at' => $set->accessTokenExpiresAt,
            // Shopify rotates the refresh token on every refresh; keep the old one only if none came back.
            'refresh_token' => $set->refreshToken ?? $integration->refresh_token,
            'refresh_token_expires_at' => $set->refreshToken !== null ? $set->refreshTokenExpiresAt : $integration->refresh_token_expires_at,
            'granted_scopes' => $set->scopes ?? $integration->granted_scopes,
        ])->save());
    }

    /** @return never */
    private function fail(ShopifyIntegration $integration, string $message): void
    {
        $wasError = $integration->status === 'error';
        $this->integrations->markError($message);

        if (! $wasError) {
            rescue(fn () => $this->notifier->notifyAdmins(ShopifyCompliance::NOTIFICATION, [
                'event' => 'token_refresh_failed',
                'shop_domain' => $integration->shop_domain,
            ]), report: true);
        }

        throw new ShopifyException('auth', $message);
    }

    private function expiresSoon(ShopifyIntegration $integration): bool
    {
        return $integration->access_token_expires_at !== null
            && $integration->access_token_expires_at->lessThanOrEqualTo(now()->addSeconds(self::REFRESH_MARGIN_SECONDS));
    }

    private function present(ShopifyIntegration $integration): string
    {
        if (blank($integration->access_token)) {
            throw new ShopifyException('not_connected', 'No Shopify access token');
        }

        return (string) $integration->access_token;
    }
}
