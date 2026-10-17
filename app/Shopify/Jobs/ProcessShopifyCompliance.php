<?php

namespace App\Shopify\Jobs;

use App\Models\WebhookEvent;
use App\Shopify\App\ShopifyCompliance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/** Handles one stored compliance webhook (ShopifyComplianceWebhookController) on the `commerce` queue. */
class ProcessShopifyCompliance implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $webhookEventId)
    {
        $this->onQueue('commerce');
    }

    public function handle(ShopifyCompliance $compliance): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        if ($event === null || in_array($event->status, ['processed', 'ignored'], true)) {
            return;
        }

        $event->increment('attempts');

        try {
            $handled = $compliance->handle((string) $event->event_type, $event->payload ?? []);
            $event->update(['status' => $handled ? 'processed' : 'ignored', 'processed_at' => now(), 'error' => null]);
        } catch (Throwable $e) {
            $event->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 2000)]);

            throw $e;
        }
    }
}
