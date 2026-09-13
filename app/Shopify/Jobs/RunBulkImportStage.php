<?php

namespace App\Shopify\Jobs;

use App\Shopify\Sync\BulkImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One step of an initial-import stage (spec §4.1). While the bulk operation is
 * pending the job re-dispatches itself with a 5 s delay doubling up to 30 s
 * (workers never sleep); on completion it dispatches the next stage. A fatal
 * failure has already marked the stage failed, so the job fails without retry.
 */
class RunBulkImportStage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly string $stage, public readonly int $pollAttempt = 0)
    {
        $this->onQueue('commerce');
    }

    public function handle(BulkImporter $importer): void
    {
        // A duplicate delivery for a finished stage must not re-import or fork the chain.
        if ($importer->stageStatus($this->stage) === 'completed') {
            return;
        }

        $importer->runStage($this->stage);

        $status = $importer->stageStatus($this->stage);

        if ($status === 'running') {
            static::dispatch($this->stage, $this->pollAttempt + 1)->delay(self::pollDelay($this->pollAttempt));

            return;
        }

        if ($status === 'completed' && ($next = BulkImporter::nextStage($this->stage)) !== null) {
            static::dispatch($next);
        }
    }

    public static function pollDelay(int $attempt): int
    {
        return min(30, 5 * (2 ** min($attempt, 5)));
    }
}
