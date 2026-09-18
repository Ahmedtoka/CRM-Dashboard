<?php

namespace App\Shopify\Jobs;

use App\Shopify\Sync\BulkImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One short step of an initial-import stage (spec §4.1). While the bulk
 * operation is pending the job re-dispatches itself with a 5 s delay doubling up
 * to 30 s (workers never sleep); while there is more to download or map it
 * re-dispatches at once; on completion it dispatches the next stage.
 *
 * At most one queued job per stage (unique until processing, so a job may
 * re-dispatch itself), and BulkImporter's per-stage lock turns a stray
 * concurrent job into a no-op. A fatal failure has already marked the stage
 * failed, so the job fails without retry.
 */
class RunBulkImportStage implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /** Below the queue's retry_after (90 s); BulkImporter keeps each step inside its time budget. */
    public int $timeout = 80;

    public int $uniqueFor = 900;

    public function __construct(public readonly string $stage, public readonly int $pollAttempt = 0)
    {
        $this->onQueue('commerce');
    }

    public function uniqueId(): string
    {
        return $this->stage;
    }

    public function handle(BulkImporter $importer): void
    {
        // A duplicate delivery for a finished stage must not re-import or fork the chain.
        if ($importer->stageStatus($this->stage) === 'completed') {
            return;
        }

        $outcome = $importer->runStage($this->stage);

        if ($outcome === BulkImporter::OUTCOME_POLLING) {
            static::dispatch($this->stage, $this->pollAttempt + 1)->delay(self::pollDelay($this->pollAttempt));
        } elseif ($outcome === BulkImporter::OUTCOME_WORKING) {
            static::dispatch($this->stage);
        } elseif ($outcome === BulkImporter::OUTCOME_COMPLETED && ($next = BulkImporter::nextStage($this->stage)) !== null) {
            static::dispatch($next);
        }
        // OUTCOME_LOCKED: another worker owns this stage and continues the chain.
    }

    public static function pollDelay(int $attempt): int
    {
        return min(30, 5 * (2 ** min($attempt, 5)));
    }
}
