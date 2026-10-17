<?php

namespace App\Shopify\App;

use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Connection\ShopifyIntegration;
use Illuminate\Http\Request;

/**
 * Shopify webhook HMAC (`X-Shopify-Hmac-Sha256` = base64 HMAC-SHA256 of the raw body), tried with
 * every secret the store may sign with, in this order (round 2026-10-17, shopify-app.md §2.4):
 *  1. each configured Partners app's client secret, then its previous secret (rotation window);
 *  2. the manual connection's api secret (the integration's api_secret, or the copy kept in
 *     legacy_credentials once the app took over: the old app keeps delivering until removed);
 *  3. crm.shopify.webhook_secret, only when no manual secret is stored at all (the old rule).
 * The label of the secret that matched is stored on webhook_events.verified_with: it tells when the
 * old app's deliveries stopped, and which app an app/uninstalled came from.
 */
final class WebhookSignature
{
    public const LEGACY = 'legacy';

    public const ENV = 'env';

    /** No secret anywhere and unsigned deliveries allowed (local/testing, demo/simulator). */
    public const UNSIGNED = 'unsigned';

    public function __construct(
        private readonly ShopifyApps $apps,
        private readonly IntegrationRepository $integrations,
    ) {}

    /**
     * @param  bool  $appsOnly  only the Partners apps' secrets (compliance webhooks come from an app only)
     * @return string|null the label of the matching secret, null when nothing verifies the delivery
     */
    public function verify(Request $request, bool $appsOnly = false): ?string
    {
        $secrets = $this->secrets($appsOnly);

        if ($secrets === []) {
            return ! $appsOnly && (app()->environment('local', 'testing') || (bool) config('crm.allow_fake_webhooks', false))
                ? self::UNSIGNED
                : null;
        }

        $header = (string) $request->header('X-Shopify-Hmac-Sha256', '');

        if ($header === '') {
            return null;
        }

        foreach ($secrets as $label => $secret) {
            if (hash_equals(base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true)), $header)) {
                return $label;
            }
        }

        return null;
    }

    public function hasAnySecret(bool $appsOnly = false): bool
    {
        return $this->secrets($appsOnly) !== [];
    }

    /** Whether a label came from a Partners app (`app:{key}` / `app_prev:{key}`); returns the app or null. */
    public function appFor(?string $label): ?ShopifyApp
    {
        if ($label === null || ! preg_match('/^app(?:_prev)?:(.+)$/', $label, $m)) {
            return null;
        }

        return $this->apps->byKey($m[1]);
    }

    /** @return array<string, string> label => secret, in verification order */
    private function secrets(bool $appsOnly): array
    {
        $secrets = [];

        foreach ($this->apps->all() as $app) {
            $secrets += $app->secrets();
        }

        if ($appsOnly) {
            return $secrets;
        }

        $legacy = $this->legacySecret($this->integrations->current());

        if ($legacy !== null) {
            $secrets[self::LEGACY] = $legacy;
        } elseif (filled(config('crm.shopify.webhook_secret'))) {
            $secrets[self::ENV] = (string) config('crm.shopify.webhook_secret');
        }

        return $secrets;
    }

    private function legacySecret(?ShopifyIntegration $integration): ?string
    {
        $secret = $integration?->api_secret;

        if (blank($secret)) {
            $secret = $integration?->legacy_credentials['api_secret'] ?? null;
        }

        return filled($secret) ? (string) $secret : null;
    }
}
