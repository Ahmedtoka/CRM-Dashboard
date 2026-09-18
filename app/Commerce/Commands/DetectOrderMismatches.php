<?php

namespace App\Commerce\Commands;

use App\Commerce\Jobs\RefreshOrderStatus;
use App\Commerce\OrderStatusResolver;
use App\Models\Order;
use Illuminate\Console\Command;

/**
 * Hourly (CommerceServiceProvider) and once after the Shopify orders import:
 * re-evaluates the time-based mismatch rules (24/48/72 h grace periods) that
 * no webhook or carrier event would otherwise trigger. Flagged orders are
 * always re-checked so a cleared condition un-flags them.
 */
class DetectOrderMismatches extends Command
{
    protected $signature = 'orders:detect-mismatch {--days=30 : Re-check orders whose shipment moved within this many days}';

    protected $description = 'Recompute Shopify/carrier status mismatches for recently active orders';

    public function handle(OrderStatusResolver $resolver): int
    {
        $since = now()->subDays(max(1, (int) $this->option('days')));
        $checked = 0;

        Order::query()
            ->where(fn ($q) => $q->where('mismatch', true)
                ->orWhereHas('shipment', fn ($s) => $s->where('last_event_at', '>=', $since)))
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
