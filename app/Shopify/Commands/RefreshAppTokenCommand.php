<?php

namespace App\Shopify\Commands;

use App\Shopify\App\ShopifyAccessTokens;
use App\Shopify\Client\ShopifyException;
use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Connection\ShopifyIntegration;
use Illuminate\Console\Command;

/**
 * Keep-alive for the Partners app token (shopify-app.md §2.3), every 30 minutes: an access token
 * with less than REFRESH_WITHIN_SECONDS left is refreshed ahead of time, so calls rarely wait for a
 * refresh and the 90-day refresh token keeps rotating even on a quiet store. A manual connection,
 * a disconnected store and the demo shop are left alone.
 */
class RefreshAppTokenCommand extends Command
{
    public const REFRESH_WITHIN_SECONDS = 2400;

    protected $signature = 'crm:shopify:refresh-app-token';

    protected $description = 'Refresh the Shopify app access token before it expires';

    public function handle(IntegrationRepository $integrations, ShopifyAccessTokens $tokens): int
    {
        $integration = $integrations->current();

        if ($integration === null || ! $integration->usesApp() || $integration->status !== 'connected'
            || $integration->shop_domain === ShopifyIntegration::DEMO_SHOP_DOMAIN) {
            $this->info('skipped');

            return self::SUCCESS;
        }

        try {
            $refreshed = $tokens->refreshIfExpiringWithin($integration, self::REFRESH_WITHIN_SECONDS);
        } catch (ShopifyException $e) {
            $this->error('failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info($refreshed ? 'refreshed' : 'still valid');

        return self::SUCCESS;
    }
}
