<?php

namespace App\Shopify\App;

/**
 * The configured Partners apps (crm.shopify.apps, D2: more than one app). Only entries with both a
 * client id and a client secret count; with none configured every app path is off and the manual
 * custom-app connection works exactly as before.
 */
final class ShopifyApps
{
    /** @return list<ShopifyApp> */
    public function all(): array
    {
        $apps = [];

        foreach ((array) config('crm.shopify.apps', []) as $key => $entry) {
            $clientId = trim((string) ($entry['client_id'] ?? ''));
            $secret = trim((string) ($entry['client_secret'] ?? ''));

            if ($clientId === '' || $secret === '') {
                continue;
            }

            $shops = is_array($entry['allowed_shops'] ?? null)
                ? $entry['allowed_shops']
                : explode(',', (string) ($entry['allowed_shops'] ?? ''));

            $apps[] = new ShopifyApp(
                (string) $key,
                $clientId,
                $secret,
                filled($entry['client_secret_previous'] ?? null) ? trim((string) $entry['client_secret_previous']) : null,
                array_values(array_filter(array_map(fn ($s) => strtolower(trim((string) $s)), $shops))),
            );
        }

        return $apps;
    }

    public function configured(): bool
    {
        return $this->all() !== [];
    }

    public function byClientId(?string $clientId): ?ShopifyApp
    {
        foreach ($this->all() as $app) {
            if ($clientId !== null && hash_equals($app->clientId, $clientId)) {
                return $app;
            }
        }

        return null;
    }

    public function byKey(string $key): ?ShopifyApp
    {
        foreach ($this->all() as $app) {
            if ($app->key === $key) {
                return $app;
            }
        }

        return null;
    }

    /** The app the embedded page is served for: the only one, or the first configured. */
    public function primary(): ?ShopifyApp
    {
        return $this->all()[0] ?? null;
    }
}
