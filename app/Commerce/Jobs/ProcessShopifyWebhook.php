<?php

namespace App\Commerce\Jobs;

use App\Models\WebhookEvent;
use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Webhooks\ShopifyWebhookProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * Applies a stored Shopify webhook (WebhookEvent provider `shopify`, event_type
 * = Shopify topic) on the `commerce` queue via ShopifyWebhookProcessor, mirroring
 * the channel webhook pipeline: stored first, processed with retries, failures
 * visible.
 */
class ProcessShopifyWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 60, 120];

    public function __construct(public readonly int $webhookEventId)
    {
        $this->onQueue('commerce');
    }

    public function handle(ShopifyWebhookProcessor $processor, IntegrationRepository $integrations): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        if ($event === null || in_array($event->status, ['processed', 'ignored'], true)) {
            return;
        }

        $event->increment('attempts');

        $topic = (string) $event->event_type;

        if ($this->shouldIgnore($topic, $integrations)) {
            $event->update(['status' => 'ignored', 'processed_at' => now(), 'error' => null]);

            return;
        }

        try {
            $processor->process($topic, $event->payload ?? []);

            $event->update([
                'status' => 'processed',
                'processed_at' => now(),
                'error' => null,
            ]);
        } catch (Throwable $e) {
            $event->update([
                'status' => 'failed',
                'error' => Str::limit($e->getMessage(), 2000),
            ]);

            throw $e;
        }
    }

    /**
     * A webhook for an unknown topic, or any topic other than app/uninstalled
     * while the integration is disconnected, is accepted and stored but never
     * handed to a mapper (plan/spec §4.2 rulings).
     */
    private function shouldIgnore(string $topic, IntegrationRepository $integrations): bool
    {
        if ($topic === 'app/uninstalled') {
            return false;
        }

        if (! in_array($topic, config('crm.shopify.webhook_topics', []), true)) {
            return true;
        }

        return $integrations->current()?->status === 'disconnected';
    }
}
