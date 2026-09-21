<?php

namespace App\Bot\Flows\Jobs;

use App\Bot\Flows\FlowPrompter;
use App\Bot\Flows\Steps\ProductLinkStep;
use App\Inbox\OutboundService;
use App\Inbox\WindowClosedException;
use App\Models\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The store lookup for an exchange product is taking a while (owner, 2026-09-21):
 * she reads «لسه بدور…» instead of watching nothing. The lookup clears the cache
 * key the moment it finishes, so a fast lookup sends this nothing.
 */
class ProductLookupStillSearching implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $conversationId, public readonly string $marker)
    {
        $this->onQueue('bot');
    }

    public function handle(): void
    {
        if (Cache::get(ProductLinkStep::lookupKey($this->conversationId)) !== $this->marker) {
            return; // the lookup already answered her
        }

        $c = Conversation::find($this->conversationId);

        if ($c === null) {
            return;
        }

        $text = app(FlowPrompter::class)->script('product_lookup_slow') ?? ProductLinkStep::STILL_SEARCHING_TEXT;

        try {
            app(OutboundService::class)->sendBot($c, $text);
        } catch (WindowClosedException) {
            Log::info('flow.product_lookup_notice_window_closed', ['conversation_id' => $c->id]);
        }
    }
}
