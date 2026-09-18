<?php

use App\Shopify\Jobs\RunManualSync;
use Illuminate\Support\Facades\Queue;

it('is unique per resource so a second manual sync for the same resource never overlaps', function () {
    Queue::fake();

    RunManualSync::dispatch('products', null, null);
    RunManualSync::dispatch('products', null, null);
    RunManualSync::dispatch('customers', null, null);

    Queue::assertPushed(RunManualSync::class, 2);
    Queue::assertPushed(RunManualSync::class, fn (RunManualSync $job) => $job->resource === 'products');
    Queue::assertPushed(RunManualSync::class, fn (RunManualSync $job) => $job->resource === 'customers');
});

it('runs an incremental sync for the requested resource and date range', function () {
    config(['crm.shopify.driver' => 'fake']);
    \App\Shopify\Connection\ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 't', 'api_secret' => 's', 'status' => 'connected']);

    $job = new RunManualSync('orders', '2026-01-01', '2026-01-31');

    $job->handle(app(\App\Shopify\Sync\IncrementalSync::class));

    expect(\App\Models\ShopifySyncRun::where('resource', 'orders')->where('type', 'manual')->exists())->toBeTrue();
});
