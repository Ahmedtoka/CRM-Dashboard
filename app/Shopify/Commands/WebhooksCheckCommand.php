<?php

namespace App\Shopify\Commands;

use App\Shopify\Jobs\CheckShopifyWebhooks;
use Illuminate\Console\Command;

/**
 * Scheduled every six hours (ShopifyServiceProvider).
 */
class WebhooksCheckCommand extends Command
{
    protected $signature = 'shopify:webhooks:check';

    protected $description = 'Re-register missing Shopify webhook subscriptions and record a webhook_health run';

    public function handle(): int
    {
        CheckShopifyWebhooks::dispatchSync();
        $this->info('Shopify webhook health check finished.');

        return self::SUCCESS;
    }
}
