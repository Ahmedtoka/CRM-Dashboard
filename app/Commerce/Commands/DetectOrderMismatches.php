<?php

namespace App\Commerce\Commands;

use App\Commerce\Jobs\RefreshOrderStatus;
use App\Commerce\OrderStatusResolver;
use App\Models\Order;
use Illuminate\Console\Command;

/**
 * Hourly (CommerceServiceProvider) and once after the Shopify orders import: re-checks every flagged order, so a
 * cleared condition (or a carrier reason left from before fresh-orders F4) un-flags it.
 */
class DetectOrderMismatches extends Command
{
    protected $signature = 'orders:detect-mismatch';

    protected $description = 'Recompute the stored order mismatch flags';

    public function handle(OrderStatusResolver $resolver): int
    {
        $checked = 0;

        Order::query()
            ->where('mismatch', true)
            ->select('id')
            ->chunkById(500, function ($orders) use ($resolver, &$checked) {
                foreach ($orders as $order) {
                    rescue(fn () => (new RefreshOrderStatus((int) $order->id))->handle($resolver), null, report: true);
                    $checked++;
                }
            });

        $flagged = Order::query()->where('mismatch', true)->count();

        $this->info("orders:detect-mismatch checked {$checked} orders, {$flagged} flagged.");

        return self::SUCCESS;
    }
}
