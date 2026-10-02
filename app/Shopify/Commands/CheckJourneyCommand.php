<?php

namespace App\Shopify\Commands;

use App\Shopify\Client\ShopifyClient;
use App\Shopify\Client\ShopifyException;
use Illuminate\Console\Command;

/**
 * Read-only: asks Shopify for one order with the customer journey, so the owner can see whether
 * SHOPIFY_CAPTURE_JOURNEY may be turned on. Never flags the integration as errored.
 */
class CheckJourneyCommand extends Command
{
    protected $signature = 'shopify:check-journey';

    protected $description = 'Check whether the Shopify token may read customerJourneySummary (utm capture)';

    public function handle(ShopifyClient $client): int
    {
        $query = '{ orders(first: 1) { edges { node { id customerJourneySummary { lastVisit { landingPage } } } } } }';

        try {
            $client->probe($query);
        } catch (ShopifyException $e) {
            $this->warn('not supported: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('supported');

        return self::SUCCESS;
    }
}
