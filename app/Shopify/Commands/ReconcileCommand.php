<?php

namespace App\Shopify\Commands;

use App\Shopify\Jobs\ReconcileShopify;
use Illuminate\Console\Command;

/**
 * Scheduled daily 03:00 Africa/Cairo (ShopifyServiceProvider). Runs in-process
 * so the schedule's withoutOverlapping/onOneServer locks cover the whole run.
 */
class ReconcileCommand extends Command
{
    protected $signature = 'shopify:reconcile';

    protected $description = 'Reconcile Shopify products, customers and orders updated since the last nightly run';

    public function handle(): int
    {
        ReconcileShopify::dispatchSync();
        $this->info('Shopify reconciliation finished.');

        return self::SUCCESS;
    }
}
