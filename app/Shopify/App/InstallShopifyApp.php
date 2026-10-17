<?php

namespace App\Shopify\App;

use App\Shopify\Connection\ConnectionTester;
use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Sync\BulkImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stores the result of a token exchange on the single integration row (shopify-app.md §6):
 *  - same store: the SAME row moves to `auth_mode = app`; the manual token and api secret are kept
 *    in legacy_credentials (the one-click rollback, and the old app's webhooks still verify);
 *    import_state is untouched and nothing is imported again (same store, same Shopify ids);
 *  - no row yet: a new one is created and the first import starts, as a manual connect does;
 *  - another store: refused (one store per CRM until multi-tenant).
 * The old app's webhook subscriptions are NOT removed here: the owner removes them from Settings
 * once the app's own webhooks are seen arriving (runbook step 7), so no order is ever missed.
 */
final class InstallShopifyApp
{
    public function __construct(
        private readonly IntegrationRepository $integrations,
        private readonly ConnectionTester $tester,
        private readonly BulkImporter $importer,
    ) {}

    /** @throws ShopNotAllowed when the CRM is connected to another store */
    public function install(ShopifyApp $app, string $shop, TokenSet $tokens): ShopifyIntegration
    {
        $integration = $this->integrations->current();

        if ($integration !== null && filled($integration->shop_domain) && strcasecmp((string) $integration->shop_domain, $shop) !== 0) {
            throw new ShopNotAllowed($shop);
        }

        $isNew = $integration === null;
        $integration ??= new ShopifyIntegration(['shop_domain' => $shop]);

        DB::transaction(function () use ($integration, $app, $shop, $tokens) {
            // The first move from the manual connection keeps its credentials aside (never overwritten later).
            if (! $integration->usesApp() && filled($integration->access_token) && $integration->legacy_credentials === null) {
                $integration->legacy_credentials = [
                    'access_token' => (string) $integration->access_token,
                    'api_secret' => filled($integration->api_secret) ? (string) $integration->api_secret : null,
                ];
            }

            $integration->forceFill([
                'shop_domain' => strtolower($shop),
                'auth_mode' => ShopifyIntegration::MODE_APP,
                'app_client_id' => $app->clientId,
                'access_token' => $tokens->accessToken,
                'access_token_expires_at' => $tokens->accessTokenExpiresAt,
                'refresh_token' => $tokens->refreshToken,
                'refresh_token_expires_at' => $tokens->refreshTokenExpiresAt,
                'granted_scopes' => $tokens->scopes ?? $integration->granted_scopes,
                // App webhooks verify with the client secret; the manual one lives in legacy_credentials.
                'api_secret' => null,
                'api_version' => config('crm.shopify.api_version'),
                'installed_at' => $integration->installed_at === null || $integration->uninstalled_at !== null ? now() : $integration->installed_at,
                'uninstalled_at' => null,
                'last_error' => null,
                'status' => 'connected',
                'connected_at' => now(),
            ])->save();
        });

        Log::info('shopify.app.installed', ['shop' => $shop, 'app' => $app->key, 'new' => $isNew]);

        if ($isNew) {
            $this->completeNewStore($integration, $tokens->accessToken);
        }

        return $integration;
    }

    /** Shop name/currency for a brand-new row, then the first import (best-effort, like ConnectShopify). */
    private function completeNewStore(ShopifyIntegration $integration, string $token): void
    {
        rescue(function () use ($integration, $token) {
            $result = $this->tester->test((string) $integration->shop_domain, $token);
            if ($result->ok) {
                $integration->forceFill(['shop_name' => $result->shopName, 'currency' => $result->currency])->save();
            }
        }, report: true);

        rescue(fn () => $this->importer->start(), report: true);
    }
}
