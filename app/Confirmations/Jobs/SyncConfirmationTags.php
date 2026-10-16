<?php

namespace App\Confirmations\Jobs;

use App\Confirmations\TagSync;
use App\Models\OrderConfirmation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Writes a confirmation's pending `wa-*` tag to Shopify off the request (slice W3).
 * One try: a failure stays in pending_tags and the 5-minute sweep retries it.
 */
final class SyncConfirmationTags implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $confirmationId) {}

    public function handle(TagSync $tags): void
    {
        $lock = Cache::lock('order-confirmation-tags:'.$this->confirmationId, 60);

        if (! $lock->get()) {
            return; // another worker is writing this row's tag; the sweep picks up anything left
        }

        try {
            if (($confirmation = OrderConfirmation::with('order')->find($this->confirmationId)) !== null) {
                $tags->flush($confirmation);
            }
        } finally {
            $lock->release();
        }
    }
}
