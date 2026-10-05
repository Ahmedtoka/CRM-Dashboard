<?php

namespace App\Ads\Control\Commands;

use App\Models\AdPublication;
use Illuminate\Console\Command;

/** Ends the 24-hour duplicate guard: a publication that finished more than 24 h ago no longer blocks a new publish (W2). */
class ClearOpenKeysCommand extends Command
{
    protected $signature = 'ads:clear-open-keys';

    protected $description = 'Release the duplicate-publish key of ads published more than 24 hours ago';

    public function handle(): int
    {
        $cutoff = now()->subDay();

        // Rows that never finished (queue lost) are failed in the same update that frees their key, so a delayed PublishAd
        // job stops at isFinished() instead of creating a second ad after the user published again.
        $stale = AdPublication::query()->whereNotNull('open_key')->where('updated_at', '<', $cutoff)
            ->whereIn('status', [AdPublication::QUEUED, AdPublication::UPLOADING, AdPublication::PROCESSING])
            ->update(['open_key' => null, 'status' => AdPublication::ERROR, 'error' => __('ads.publish.never_sent'), 'updated_at' => now()]);

        // done, and error rows whose ad request was sent (the ad may exist, so the key was held): untouched for 24 h.
        $n = $stale + AdPublication::query()->whereNotNull('open_key')->where('updated_at', '<', $cutoff)
            ->whereIn('status', [AdPublication::DONE, AdPublication::ERROR])
            ->update(['open_key' => null]);
        $this->info("Released {$n} open key(s).");

        return self::SUCCESS;
    }
}
