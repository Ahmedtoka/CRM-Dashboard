<?php

namespace App\Shopify\Sync;

use App\Models\ShopifySyncRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Writes the `shopify_sync_runs` log (spec §4.1, §4.3, §4.5). Types: `initial`
 * (one run per import stage), `nightly`, `manual`, `webhook_health`.
 */
final class SyncRunRecorder
{
    public const MAX_ERRORS = 200;

    public function open(string $type, string $resource, ?CarbonInterface $from = null, ?CarbonInterface $to = null): ShopifySyncRun
    {
        return ShopifySyncRun::create([
            'type' => $type,
            'resource' => $resource,
            'range_from' => $from,
            'range_to' => $to,
            'status' => 'running',
            'errors' => [],
            'started_at' => now(),
        ]);
    }

    /** Appends one error; beyond MAX_ERRORS only the `failed` counter keeps growing. */
    public function recordError(ShopifySyncRun $run, string $ref, string $message): void
    {
        $errors = $run->errors ?? [];

        if (count($errors) >= self::MAX_ERRORS) {
            return;
        }

        $errors[] = [
            'ref' => Str::limit($ref, 120),
            'message' => Str::limit($message, 500),
            'at' => now()->toIso8601String(),
        ];

        $run->forceFill(['errors' => $errors])->save();
    }

    /** Persists running counters so a long run shows progress before it closes. */
    public function progress(ShopifySyncRun $run, SyncRunSummary $summary): void
    {
        $run->forceFill($this->counters($summary))->save();
    }

    public function close(ShopifySyncRun $run, SyncRunSummary $summary, string $status): void
    {
        $run->forceFill($this->counters($summary) + [
            'status' => $status,
            'finished_at' => now(),
        ])->save();
    }

    /** @return array<string, int> */
    private function counters(SyncRunSummary $summary): array
    {
        return [
            'processed' => $summary->processed,
            'created' => $summary->created,
            'updated' => $summary->updated,
            'skipped_stale' => $summary->skippedStale,
            'failed' => $summary->failed,
        ];
    }
}
