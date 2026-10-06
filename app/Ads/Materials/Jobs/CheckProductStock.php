<?php

namespace App\Ads\Materials\Jobs;

use App\Ads\Materials\StockWatcher;
use App\Models\AdMaterial;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Re-checks the stock flag of the running materials of some products, after a Shopify product/inventory update. */
class CheckProductStock implements ShouldQueue
{
    use Queueable;

    /** @param  list<int>  $productIds */
    public function __construct(public array $productIds) {}

    public function handle(StockWatcher $watcher): void
    {
        $watcher->run($this->productIds);
    }

    /**
     * Shopify sync hook: queue a check for products that have a running material. Never inline, never throws.
     *
     * @param  iterable<int|string|null>  $productIds
     */
    public static function dispatchFor(iterable $productIds): void
    {
        try {
            $ids = collect($productIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();
            if ($ids->isEmpty()) {
                return;
            }

            $watched = AdMaterial::query()->where('status', 'live')->whereIn('product_id', $ids)
                ->distinct()->pluck('product_id')->map(fn ($id) => (int) $id)->all();

            if ($watched !== []) {
                self::dispatch($watched);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
