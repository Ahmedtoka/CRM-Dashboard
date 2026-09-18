<?php

namespace App\Shopify\Commands;

use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Sync\BulkImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Scheduled daily 03:30 Africa/Cairo (ShopifyServiceProvider): re-imports the
 * Shopify shipping zones / rates only, so the bot's shipping quotes
 * (App\Commerce\ShippingQuote) follow any fee change in Shopify. Shopify has
 * no delivery-profile webhook in the subscribed topics, so this is the sync.
 */
class SyncShippingCommand extends Command
{
    protected $signature = 'shopify:sync-shipping';

    protected $description = 'Re-import the Shopify shipping zones and rates';

    public function handle(BulkImporter $importer, IntegrationRepository $integrations): int
    {
        if ($integrations->current()?->status !== 'connected') {
            $this->info('Shopify is not connected; nothing to sync.');

            return self::SUCCESS;
        }

        try {
            $count = $importer->syncShipping('nightly');
        } catch (Throwable $e) {
            report($e);
            $this->error('Shopify shipping sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Shopify shipping zones synced ({$count}).");

        return self::SUCCESS;
    }
}
