<?php

namespace App\Shopify\Connection;

use App\Shopify\Client\ShopifyClient;
use App\Shopify\Client\ShopifyException;

/**
 * Validates a domain + token pair before an integration is saved (spec §2,
 * §7): reads shop name/currency/granted scopes and reports missing scopes.
 * Never touches the stored integration — uses ShopifyClient::forCredentials().
 */
final class ConnectionTester
{
    private const DOMAIN_PATTERN = '/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/';

    private const QUERY = '{ shop { name currencyCode } currentAppInstallation { accessScopes { handle } } }';

    public function __construct(private readonly ShopifyClient $client) {}

    public function test(string $domain, string $token): ConnectionTestResult
    {
        if (! preg_match(self::DOMAIN_PATTERN, $domain)) {
            return new ConnectionTestResult(
                ok: false,
                shopName: null,
                currency: null,
                grantedScopes: [],
                missingScopes: [],
                error: 'Invalid Shopify domain',
            );
        }

        try {
            $data = $this->client->forCredentials($domain, $token)->query(self::QUERY);
        } catch (ShopifyException $e) {
            return new ConnectionTestResult(
                ok: false,
                shopName: null,
                currency: null,
                grantedScopes: [],
                missingScopes: [],
                error: $e->getMessage(),
            );
        }

        $granted = array_map(
            static fn (array $scope): string => $scope['handle'],
            $data['currentAppInstallation']['accessScopes'] ?? [],
        );
        $required = config('crm.shopify.required_scopes', []);
        $missing = array_values(array_diff($required, $granted));

        return new ConnectionTestResult(
            ok: true,
            shopName: $data['shop']['name'] ?? null,
            currency: $data['shop']['currencyCode'] ?? null,
            grantedScopes: $granted,
            missingScopes: $missing,
            error: null,
        );
    }
}
