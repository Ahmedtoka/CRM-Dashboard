<?php

namespace App\Shopify\Jobs;

use App\Shopify\Sync\BulkImporter;
use App\Shopify\Sync\IncrementalSync;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Owner-triggered manual sync from the connection screen (spec §4.5, §7). One
 * job per resource at a time (`uniqueId` = resource) so a double click never
 * runs two overlapping windows for the same resource.
 */
class RunManualSync implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    /** Releases the unique lock even if a worker dies mid-job. */
    public int $uniqueFor = 1800;

    public function __construct(
        public readonly string $resource,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
    ) {
        // Longer than the `redis` connection's retry_after (90 s): on Redis it
        // runs on `redis-long` (retry_after 3700 s); any other default
        // connection (database/sync locally) is kept as is.
        $this->onQueue('commerce-long');

        if (config('queue.default') === 'redis') {
            $this->onConnection('redis-long');
        }
    }

    public function uniqueId(): string
    {
        return $this->resource;
    }

    public function handle(IncrementalSync $sync): void
    {
        // Shipping zones have no "updated since" window: the whole list is re-imported.
        if ($this->resource === 'shipping') {
            app(BulkImporter::class)->syncShipping('manual');

            return;
        }

        $since = $this->from !== null
            ? Carbon::parse($this->from)->startOfDay()
            : now()->subDay();

        $until = $this->to !== null
            ? Carbon::parse($this->to)->endOfDay()
            : null;

        $sync->run($this->resource, $since, $until, 'manual');
    }
}
