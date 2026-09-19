<?php

namespace App\Shopify\Connection;

use App\Shopify\Sync\BulkImporter;
use App\Shopify\Webhooks\WebhookRegistrar;

/**
 * Connect/re-connect flow for the settings screen (spec §7). Idempotent: an
 * existing integration row (any status) is updated in place rather than
 * duplicated, so re-entering credentials after an `error`/`disconnected`
 * status — or simply rotating the token — never creates a second row.
 */
final class ConnectShopify
{
    public function __construct(
        private readonly ConnectionTester $tester,
        private readonly IntegrationRepository $integrations,
        private readonly WebhookRegistrar $webhooks,
        private readonly BulkImporter $importer,
    ) {}

    /**
     * @return array{ok: bool, missing_scopes?: list<string>, error?: ?string}
     */
    public function connect(string $domain, string $token, ?string $secret = null): array
    {
        $result = $this->tester->test($domain, $token);

        if (! $result->ok) {
            return ['ok' => false, 'missing_scopes' => [], 'error' => $result->error];
        }

        if (array_diff($result->missingScopes, config('crm.shopify.optional_scopes', [])) !== []) {
            return ['ok' => false, 'missing_scopes' => $result->missingScopes, 'error' => null];
        }

        $integration = $this->integrations->current();
        // A different store is a fresh import: the previous store's finished stages
        // must not make the new store's import "resume" and skip them.
        $sameStore = $integration !== null && strcasecmp((string) $integration->shop_domain, $domain) === 0;
        if ($integration !== null && ! $sameStore) {
            $integration->forceFill(['import_state' => null])->save();
        }
        $resuming = $sameStore && $this->hasCompletedStage($integration);
        $integration ??= new ShopifyIntegration();

        $integration->forceFill([
            'shop_domain' => $domain,
            'shop_name' => $result->shopName,
            'currency' => $result->currency,
            'access_token' => $token,
            // A blank secret on re-connect keeps the one already stored.
            'api_secret' => $secret ?? $integration->api_secret,
            'api_version' => config('crm.shopify.api_version'),
            'granted_scopes' => $result->grantedScopes,
            'last_error' => null,
        ])->save();

        $this->integrations->markConnected();

        // Best-effort: WebhookRegistrar never throws for a per-topic failure.
        $this->webhooks->register();

        $resuming ? $this->importer->resume() : $this->importer->start();

        return ['ok' => true];
    }

    private function hasCompletedStage(ShopifyIntegration $integration): bool
    {
        foreach ($integration->import_state['stages'] ?? [] as $stage) {
            if (($stage['status'] ?? null) === 'completed') {
                return true;
            }
        }

        return false;
    }
}
