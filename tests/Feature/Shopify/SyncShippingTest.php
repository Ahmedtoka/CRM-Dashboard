<?php

use App\Enums\UserRole;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\ShopifySyncRun;
use App\Models\User;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Jobs\RunManualSync;
use App\Shopify\Sync\BulkImporter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Event::fake();
    // The fake transport serves the demo store's delivery profiles (27 governorates, 5 tiers).
    config(['crm.shopify.driver' => 'fake']);
});

function connectedShopify(array $state = []): ShopifyIntegration
{
    return ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 't', 'api_secret' => 's', 'status' => 'connected', 'import_state' => $state]);
}

it('re-imports the shipping zones from Shopify, replacing stale rates, and records a nightly run', function () {
    $state = ['stages' => ['shipping' => ['status' => 'completed', 'processed' => 3]]];
    connectedShopify($state);
    $stale = ShippingRate::factory()->create(['price' => '999.00']);

    $this->artisan('shopify:sync-shipping')->assertSuccessful();

    $run = ShopifySyncRun::sole();

    expect(ShippingRate::find($stale->id))->toBeNull()
        ->and(ShippingZone::find($stale->shipping_zone_id))->toBeNull()
        ->and(ShippingZone::count())->toBeGreaterThan(0)
        ->and(ShippingRate::count())->toBeGreaterThan(0)
        ->and($run->type)->toBe('nightly')
        ->and($run->resource)->toBe('shipping')
        ->and($run->status)->toBe('completed')
        ->and($run->processed)->toBe(ShippingZone::count())
        // The initial import's progress is not touched.
        ->and(ShopifyIntegration::first()->import_state)->toBe($state);
});

it('does nothing when Shopify is not connected', function () {
    $this->artisan('shopify:sync-shipping')->assertSuccessful();

    expect(ShopifySyncRun::count())->toBe(0);
});

it('never overlaps an import of the shipping stage', function () {
    connectedShopify();
    $existing = ShippingRate::factory()->create();
    $lock = Cache::lock('shopify-import-shipping', 60);
    $lock->get();

    try {
        $this->artisan('shopify:sync-shipping')->assertFailed();
    } finally {
        $lock->release();
    }

    expect(ShippingRate::find($existing->id))->not->toBeNull();
});

it('records a failed run and keeps the local zones when Shopify fails', function () {
    connectedShopify();
    $existing = ShippingRate::factory()->create();
    ShopifyIntegration::first()->update(['status' => 'error']);

    expect(fn () => app(BulkImporter::class)->syncShipping('manual'))->toThrow(Exception::class);

    expect(ShippingRate::find($existing->id))->not->toBeNull()
        ->and(ShopifySyncRun::sole()->status)->toBe('failed');
});

it('offers shipping as a "sync now" resource on the Shopify settings page', function () {
    Queue::fake();
    connectedShopify();
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->postJson('/settings/shopify/sync', ['resource' => 'shipping'])->assertOk();

    Queue::assertPushed(RunManualSync::class, fn (RunManualSync $job) => $job->resource === 'shipping');
});

it('runs the manual shipping sync as a manual run', function () {
    connectedShopify();

    app()->call([new RunManualSync('shipping'), 'handle']);

    expect(ShopifySyncRun::sole()->only(['type', 'resource', 'status']))->toBe(['type' => 'manual', 'resource' => 'shipping', 'status' => 'completed'])
        ->and(ShippingRate::count())->toBeGreaterThan(0);
});

it('schedules the shipping sync daily at 03:30 Cairo', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'shopify:sync-shipping'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 3 * * *')
        ->and($event->timezone)->toBe('Africa/Cairo')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
