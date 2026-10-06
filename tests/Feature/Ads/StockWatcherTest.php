<?php

use App\Ads\Materials\Jobs\CheckProductStock;
use App\Ads\Materials\MaterialService;
use App\Ads\Materials\StockWatcher;
use App\Enums\UserRole;
use App\Models\AdMaterial;
use App\Models\MediaBuyer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\UserNotification;
use App\Shopify\Sync\Mappers\InventoryMapper;
use App\Shopify\Sync\Mappers\ProductMapper;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Queue;

/** @return array{buyerUser: User, buyer: MediaBuyer, supervisor: User, admin: User, product: Product, material: AdMaterial} */
function swWorld(int $stock = 0, array $material = []): array
{
    $buyerUser = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $buyer = MediaBuyer::factory()->create(['user_id' => $buyerUser->id]);
    $product = Product::factory()->create(['title' => 'Abaya Noor']);
    ProductVariant::factory()->create(['product_id' => $product->id, 'inventory_quantity' => $stock]);
    ProductVariant::factory()->create(['product_id' => $product->id, 'inventory_quantity' => 0]);

    return [
        'buyerUser' => $buyerUser, 'buyer' => $buyer, 'product' => $product,
        'supervisor' => User::factory()->create(['role' => UserRole::Supervisor]),
        'admin' => User::factory()->create(['role' => UserRole::Admin]),
        'material' => AdMaterial::factory()->create(array_merge([
            'title' => 'Eid reel', 'status' => 'live', 'product_id' => $product->id, 'media_buyer_id' => $buyer->id,
        ], $material)),
    ];
}

function swNotes(string $type = 'ads.need_stop')
{
    return UserNotification::where('type', $type)->get();
}

it('flags an out-of-stock running material and notifies the buyer and supervisors once', function () {
    $w = swWorld();

    expect(app(StockWatcher::class)->run())->toBe(['flagged' => 1, 'cleared' => 0]);

    expect($w['material']->fresh()->need_stop_at)->not->toBeNull()
        ->and(swNotes())->toHaveCount(3)
        ->and(swNotes()->pluck('user_id')->sort()->values()->all())->toBe(collect([$w['buyerUser']->id, $w['supervisor']->id, $w['admin']->id])->sort()->values()->all());

    $data = swNotes()->first()->data;
    expect($data['material_id'])->toBe($w['material']->id)->and($data['title'])->toBe('Eid reel')
        ->and($data['product_title'])->toBe('Abaya Noor')->and($data['link'])->toBe('/ads/materials?status=live&stock=out');
});

it('does not notify again on later runs', function () {
    swWorld();
    $watcher = app(StockWatcher::class);
    $watcher->run();

    expect($watcher->run())->toBe(['flagged' => 0, 'cleared' => 0])->and(swNotes())->toHaveCount(3);
});

it('clears the flag when stock returns, silently, and a new episode notifies again', function () {
    $w = swWorld();
    $watcher = app(StockWatcher::class);
    $watcher->run();

    ProductVariant::where('product_id', $w['product']->id)->update(['inventory_quantity' => 4]);
    expect($watcher->run())->toBe(['flagged' => 0, 'cleared' => 1])
        ->and($w['material']->fresh()->need_stop_at)->toBeNull()
        ->and(swNotes())->toHaveCount(3);

    ProductVariant::where('product_id', $w['product']->id)->update(['inventory_quantity' => 0]);
    expect($watcher->run()['flagged'])->toBe(1)->and(swNotes())->toHaveCount(6);
});

it('ignores not started and done materials, materials without a product and in-stock ones', function () {
    $w = swWorld();
    $w['material']->update(['status' => 'new']);
    swWorld(0, ['status' => 'retired']);
    swWorld(0, ['product_id' => null]);
    swWorld(3);

    expect(app(StockWatcher::class)->run())->toBe(['flagged' => 0, 'cleared' => 0])->and(swNotes())->toHaveCount(0);
});

it('counts a manual out-of-stock override as out, and a manual in-stock override as in', function () {
    $w = swWorld(5, ['stock_override' => false]);
    swWorld(0, ['stock_override' => true]);

    expect(app(StockWatcher::class)->run()['flagged'])->toBe(1)
        ->and($w['material']->fresh()->need_stop_at)->not->toBeNull();
});

it('does not double-notify a buyer who is also a supervisor, and skips an inactive or unlinked buyer', function () {
    $w = swWorld();
    $w['buyerUser']->update(['role' => UserRole::Supervisor]);
    app(StockWatcher::class)->run();
    expect(swNotes()->where('user_id', $w['buyerUser']->id))->toHaveCount(1)->and(swNotes())->toHaveCount(3);

    UserNotification::query()->delete();
    AdMaterial::query()->update(['need_stop_at' => null]);
    $w['buyerUser']->update(['is_active' => false]);
    app(StockWatcher::class)->run();
    expect(swNotes()->where('user_id', $w['buyerUser']->id))->toHaveCount(0)->and(swNotes())->toHaveCount(2);

    UserNotification::query()->delete();
    AdMaterial::query()->update(['need_stop_at' => null]);
    $w['buyer']->update(['user_id' => null]);
    app(StockWatcher::class)->run();
    expect(swNotes())->toHaveCount(2);
});

it('limits a run to the given products', function () {
    $a = swWorld();
    $b = swWorld();

    expect(app(StockWatcher::class)->run([$a['product']->id]))->toBe(['flagged' => 1, 'cleared' => 0])
        ->and($b['material']->fresh()->need_stop_at)->toBeNull();
});

it('runs from the ads:stock-watch command and is scheduled every 30 minutes without overlapping', function () {
    $w = swWorld();
    $this->artisan('ads:stock-watch')->expectsOutput('Flagged 1, cleared 0.')->assertSuccessful();
    expect($w['material']->fresh()->need_stop_at)->not->toBeNull();

    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'ads:stock-watch'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('*/30 * * * *')->and($event->withoutOverlapping)->toBeTrue();
});

it('lets an ads-role user open the notification list', function () {
    $w = swWorld();
    app(StockWatcher::class)->run();

    $this->actingAs($w['buyerUser'])->getJson('/notifications')->assertOk()->assertJsonFragment(['type' => 'ads.need_stop']);
});

it('queues a stock check after an inventory update for a running material, never inline', function () {
    Queue::fake();
    $w = swWorld();
    ProductVariant::where('product_id', $w['product']->id)->first()->update(['inventory_item_id' => '9001']);

    app(InventoryMapper::class)->apply(['inventory_item_id' => '9001', 'available' => 0]);

    Queue::assertPushed(CheckProductStock::class, fn ($j) => $j->productIds === [$w['product']->id]);
    expect($w['material']->fresh()->need_stop_at)->toBeNull();
});

it('does not queue a stock check for products without a running material', function () {
    Queue::fake();
    $product = Product::factory()->create();
    ProductVariant::factory()->create(['product_id' => $product->id, 'inventory_item_id' => '9002']);

    app(InventoryMapper::class)->apply(['inventory_item_id' => '9002', 'available' => 0]);

    Queue::assertNothingPushed();
});

it('queues a stock check after a product upsert', function () {
    Queue::fake();
    $w = swWorld(3);

    app(ProductMapper::class)->upsert(['id' => $w['product']->shopify_id, 'title' => 'Abaya Noor', 'variants' => []]);

    Queue::assertPushed(CheckProductStock::class);
});

it('never lets a failing dispatch break the Shopify sync', function () {
    $w = swWorld();
    ProductVariant::where('product_id', $w['product']->id)->first()->update(['inventory_item_id' => '9003']);
    $this->mock(Dispatcher::class)->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));

    app(InventoryMapper::class)->apply(['inventory_item_id' => '9003', 'available' => 0]);

    expect(ProductVariant::where('inventory_item_id', '9003')->value('inventory_quantity'))->toBe(0);
});

it('the queued job runs the watcher for its products', function () {
    $w = swWorld();
    (new CheckProductStock([$w['product']->id]))->handle(app(StockWatcher::class));

    expect($w['material']->fresh()->need_stop_at)->not->toBeNull();
});

it('clears need stop when a material leaves activated and notifies again when it is re-activated still out of stock', function () {
    $w = swWorld();
    $service = app(MaterialService::class);
    app(StockWatcher::class)->run();
    expect($service->stats()['need_stop'])->toBe(1)->and(swNotes())->toHaveCount(3);

    $w['material']->fresh()->forceFill(['status' => 'retired', 'need_stop_at' => null])->save(); // status is derived now (S1)
    expect($w['material']->fresh()->need_stop_at)->toBeNull()->and($service->stats()['need_stop'])->toBe(0);

    // a stale flag on a non-activated row (legacy data) is not counted either
    AdMaterial::whereKey($w['material']->id)->update(['need_stop_at' => now()]);
    expect($service->stats()['need_stop'])->toBe(0);
    AdMaterial::whereKey($w['material']->id)->update(['need_stop_at' => null]);

    $w['material']->fresh()->forceFill(['status' => 'live'])->save();

    app(StockWatcher::class)->run();
    expect($w['material']->fresh()->need_stop_at)->not->toBeNull()->and(swNotes())->toHaveCount(6); // a new episode
});
