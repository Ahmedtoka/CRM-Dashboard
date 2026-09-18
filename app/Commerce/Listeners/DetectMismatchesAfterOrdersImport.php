<?php

namespace App\Commerce\Listeners;

use App\Events\IntegrationProgress;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * The bulk import maps orders with per-order side effects suppressed, so the
 * mismatch pass runs once when the `orders` stage reports completed. There is
 * no dedicated stage-completed event: IntegrationProgress carries the stage
 * state on every change, and a cache guard keyed on the stage's sync run makes
 * repeated progress pushes for the same finished stage a no-op.
 */
final class DetectMismatchesAfterOrdersImport
{
    public function handle(IntegrationProgress $event): void
    {
        $stage = $event->importState['stages']['orders'] ?? null;

        if (! is_array($stage) || ($stage['status'] ?? null) !== 'completed') {
            return;
        }

        $runKey = $stage['run_id'] ?? $stage['bulk_operation_id'] ?? null;

        // Without a run identity there is nothing safe to key on (a shared key would
        // swallow the next import's pass), so that run skips the guard.
        if ($runKey !== null && ! Cache::add('orders:detect-mismatch:import:'.$runKey, true, now()->addHours(6))) {
            return;
        }

        rescue(fn () => Artisan::queue('orders:detect-mismatch')->onQueue('commerce'), null, report: true);
    }
}
