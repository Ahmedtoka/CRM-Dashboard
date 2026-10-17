<?php

namespace App\Shopify\App;

use App\Shopify\Connection\ConnectionTester;
use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Webhooks\WebhookRegistrar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one-click rollback (shopify-app.md §2.6, §8): the store goes back to the manual custom-app
 * token and api secret kept in legacy_credentials, and its API webhook subscriptions are registered
 * again with that token. The kept credentials stay on file: they also mark the store as rolled back
 * on purpose, so opening the app in Shopify admin does not move it back (InstallShopifyApp) until
 * the owner allows it again from Settings.
 *
 * The old token is tested first (review M2): if the old app was already removed, nothing changes.
 * The row is rewritten under the store's token lock, so a refresh in flight cannot put an app token
 * back afterwards (review H1).
 *
 * The Partners app's own (shopify.app.toml) webhooks keep arriving until its TOML is deployed
 * without them (runbook); they still verify, are processed idempotently and cannot disconnect the
 * store (app/uninstalled is scoped to the connected app).
 */
final class RollbackToManualConnection
{
    public const OK = 'ok';

    public const NOTHING_KEPT = 'nothing_kept';

    public const TOKEN_REFUSED = 'token_refused';

    public function __construct(
        private readonly IntegrationRepository $integrations,
        private readonly WebhookRegistrar $webhooks,
        private readonly ConnectionTester $tester,
        private readonly ShopifyAccessTokens $tokens,
    ) {}

    /** @return string self::OK, self::NOTHING_KEPT or self::TOKEN_REFUSED */
    public function rollback(): string
    {
        $integration = $this->integrations->current();
        $token = $integration?->legacy_credentials['access_token'] ?? null;

        if ($integration === null || ! $integration->usesApp() || blank($token)) {
            return self::NOTHING_KEPT;
        }

        if (! $this->tester->test((string) $integration->shop_domain, (string) $token)->ok) {
            return self::TOKEN_REFUSED;
        }

        $this->tokens->locked((string) $integration->shop_domain, fn () => DB::transaction(fn () => $integration->refresh()->forceFill([
            'auth_mode' => ShopifyIntegration::MODE_CUSTOM_TOKEN,
            'access_token' => (string) $token,
            'api_secret' => $integration->legacy_credentials['api_secret'] ?? null,
            'access_token_expires_at' => null,
            'refresh_token' => null,
            'refresh_token_expires_at' => null,
        ])->save()));

        $this->integrations->markConnected();

        // Best-effort, like a manual connect: WebhookRegistrar never throws for one topic.
        rescue(fn () => $this->webhooks->register(), report: true);

        Log::warning('shopify.app.rolled_back', ['shop' => $integration->shop_domain]);

        return self::OK;
    }

    /** «اسمح بالرجوع للأبلكيشن»: lift the rollback mark, so the next app open moves the store again. */
    public function allowAppAgain(): bool
    {
        $integration = $this->integrations->current();

        if ($integration === null || $integration->usesApp() || $integration->legacy_credentials === null) {
            return false;
        }

        // The current manual token/secret are still on the row; the next install keeps them aside again.
        $integration->forceFill(['legacy_credentials' => null])->save();

        return true;
    }
}
