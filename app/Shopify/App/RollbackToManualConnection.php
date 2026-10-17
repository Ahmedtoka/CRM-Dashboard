<?php

namespace App\Shopify\App;

use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Webhooks\WebhookRegistrar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one-click rollback (shopify-app.md §2.6, §8): the store goes back to the manual custom-app
 * token and api secret kept in legacy_credentials, and its API webhook subscriptions are registered
 * again with that token. The kept credentials stay on file, so the app can be opened again later.
 * The Partners app's own (shopify.app.toml) webhooks keep arriving until its TOML is deployed
 * without them (runbook); they still verify, are processed idempotently and cannot disconnect the
 * store (app/uninstalled is scoped to the connected app).
 */
final class RollbackToManualConnection
{
    public function __construct(
        private readonly IntegrationRepository $integrations,
        private readonly WebhookRegistrar $webhooks,
    ) {}

    /** @return bool false when there is nothing to roll back to */
    public function rollback(): bool
    {
        $integration = $this->integrations->current();
        $token = $integration?->legacy_credentials['access_token'] ?? null;

        if ($integration === null || ! $integration->usesApp() || blank($token)) {
            return false;
        }

        DB::transaction(fn () => $integration->forceFill([
            'auth_mode' => ShopifyIntegration::MODE_CUSTOM_TOKEN,
            'access_token' => (string) $token,
            'api_secret' => $integration->legacy_credentials['api_secret'] ?? null,
            'access_token_expires_at' => null,
            'refresh_token' => null,
            'refresh_token_expires_at' => null,
        ])->save());

        $this->integrations->markConnected();

        // Best-effort, like a manual connect: WebhookRegistrar never throws for one topic.
        rescue(fn () => $this->webhooks->register(), report: true);

        Log::warning('shopify.app.rolled_back', ['shop' => $integration->shop_domain]);

        return true;
    }
}
