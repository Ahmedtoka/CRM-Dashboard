<?php

use App\Enums\UserRole;
use App\Events\UserNotified;
use App\Models\Order;
use App\Models\ShopifySyncRun;
use App\Models\User;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Jobs\ReconcileShopify;
use App\Shopify\Sync\IncrementalSync;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

it('pages through updated orders and skips stale ones', function () {
    Event::fake();
    config(['crm.shopify.driver' => 'live']);
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 't', 'api_secret' => 's', 'status' => 'connected']);
    $fx = fn ($n) => json_decode(file_get_contents(base_path("tests/Fixtures/shopify/{$n}")), true);
    $sequence = Http::fakeSequence('demo.myshopify.com/*')->push($fx('orders_page_1.json'))->push($fx('orders_page_2.json'));
    $s1 = app(IncrementalSync::class)->run('orders', now()->subDay());
    expect($s1->processed)->toBeGreaterThan(0)->and(Order::count())->toBe($s1->created);
    // Http fakes match in registration order, so refill the same sequence rather than registering a second one.
    $sequence->push($fx('orders_page_1.json'))->push($fx('orders_page_2.json'));
    $s2 = app(IncrementalSync::class)->run('orders', now()->subDay());
    expect($s2->skippedStale)->toBe($s1->processed)->and($s2->created)->toBe(0);

    $run = ShopifySyncRun::latest('id')->first();
    expect($run->type)->toBe('manual')->and($run->resource)->toBe('orders')->and($run->status)->toBe('completed')
        ->and($run->skipped_stale)->toBe($s1->processed);
    Http::assertSent(fn ($r) => str_contains($r['variables']['query'] ?? '', "updated_at:>='") && ($r['variables']['first'] ?? null) === 250);
});

function reconcileFake(): void
{
    $page = json_decode(file_get_contents(base_path('tests/Fixtures/shopify/orders_page_2.json')), true);

    Http::fake(['demo.myshopify.com/*' => function ($req) use ($page) {
        $q = $req['query'] ?? '';
        if (str_contains($q, 'orders(')) {
            return Http::response($page);
        }
        $root = str_contains($q, 'customers(') ? 'customers' : 'products';

        return Http::response(['data' => [$root => ['edges' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]);
    }]);
}

it('reconciles since the last completed nightly run, capped at 72 hours, and notifies admins of fixed orders', function () {
    $this->freezeTime();
    Event::fake();
    config(['crm.shopify.driver' => 'live']);
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 't', 'api_secret' => 's', 'status' => 'connected']);
    $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    ShopifySyncRun::create(['type' => 'nightly', 'resource' => 'orders', 'status' => 'completed', 'started_at' => now()->subDays(5), 'finished_at' => now()->subDays(5)]);
    reconcileFake();

    app()->call([new ReconcileShopify, 'handle']);

    $since = now()->subHours(72)->utc()->format('Y-m-d\TH:i');
    Http::assertSent(fn ($r) => str_contains($r['query'] ?? '', 'orders(') && str_contains($r['variables']['query'] ?? '', $since));
    expect(ShopifySyncRun::where('type', 'nightly')->where('status', 'completed')->count())->toBe(4);
    Event::assertDispatched(UserNotified::class, fn (UserNotified $e) => $e->userId === $admin->id && $e->type === 'shopify.reconciled' && $e->data['count'] === 1);
});

it('does not notify admins when reconciliation changed no orders', function () {
    Event::fake();
    config(['crm.shopify.driver' => 'live']);
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 't', 'api_secret' => 's', 'status' => 'connected']);
    User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    reconcileFake();

    app()->call([new ReconcileShopify, 'handle']);
    app()->call([new ReconcileShopify, 'handle']); // second pass: everything is stale

    Event::assertDispatchedTimes(UserNotified::class, 1);
});

it('schedules nightly reconciliation and the webhook health check', function () {
    $events = collect(app(Schedule::class)->events());
    $reconcile = $events->first(fn ($e) => str_contains($e->command ?? '', 'shopify:reconcile'));
    $health = $events->first(fn ($e) => str_contains($e->command ?? '', 'shopify:webhooks:check'));

    expect($reconcile)->not->toBeNull()
        ->and($reconcile->expression)->toBe('0 3 * * *')
        ->and($reconcile->timezone)->toBe('Africa/Cairo')
        ->and($reconcile->withoutOverlapping)->toBeTrue()
        ->and($reconcile->onOneServer)->toBeTrue()
        ->and($health)->not->toBeNull()
        ->and($health->expression)->toBe('0 */6 * * *');
});
