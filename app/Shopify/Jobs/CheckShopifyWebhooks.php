<?php

namespace App\Shopify\Jobs;

use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Webhooks\WebhookRegistrar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Six-hourly webhook health check (spec §4.3): re-registers missing topics and
 * records a `webhook_health` sync run (WebhookRegistrar::check).
 */
class CheckShopifyWebhooks implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('commerce');
    }

    public function handle(WebhookRegistrar $registrar, IntegrationRepository $integrations): void
    {
        if ($integrations->current()?->status !== 'connected') {
            return;
        }

        $registrar->check();
    }
}
