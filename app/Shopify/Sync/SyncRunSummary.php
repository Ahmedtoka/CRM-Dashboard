<?php

namespace App\Shopify\Sync;

use App\Models\ShopifySyncRun;
use App\Shopify\Sync\Mappers\MapResult;

/**
 * Counters of one sync run. `processed` counts every row seen, including failed ones.
 */
final readonly class SyncRunSummary
{
    public function __construct(
        public int $processed,
        public int $created,
        public int $updated,
        public int $skippedStale,
        public int $failed,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0, 0, 0, 0);
    }

    public static function fromRun(ShopifySyncRun $run): self
    {
        return new self((int) $run->processed, (int) $run->created, (int) $run->updated, (int) $run->skipped_stale, (int) $run->failed);
    }

    public function withResult(MapResult $result): self
    {
        return new self(
            $this->processed + 1,
            $this->created + ($result === MapResult::Created ? 1 : 0),
            $this->updated + ($result === MapResult::Updated ? 1 : 0),
            $this->skippedStale + ($result === MapResult::Skipped ? 1 : 0),
            $this->failed,
        );
    }

    public function withFailure(): self
    {
        return new self($this->processed + 1, $this->created, $this->updated, $this->skippedStale, $this->failed + 1);
    }

    public function withProcessed(int $processed): self
    {
        return new self($processed, $this->created, $this->updated, $this->skippedStale, $this->failed);
    }
}
