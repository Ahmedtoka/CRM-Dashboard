<?php

use App\Ads\AdsSettings;
use App\Ads\Materials\StockWatcher;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdMaterial;
use App\Models\MediaBuyer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\UserNotification;

/** Round 3: «خطر مخزون» — a running ad whose product is about to run out (at or under ads_settings.stock_low_threshold). */

/** @return array{buyerUser: User, supervisor: User, product: Product, material: AdMaterial, ad: Ad} */
function slWorld(int $stock = 3, bool $running = true, string $adName = 'Eid reel A'): array
{
    $buyerUser = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $buyer = MediaBuyer::factory()->create(['user_id' => $buyerUser->id]);
    $product = Product::factory()->create(['title' => 'Abaya Noor']);
    ProductVariant::factory()->create(['product_id' => $product->id, 'inventory_quantity' => $stock]);
    $acc = AdAccount::factory()->meta()->create(['name' => 'LV Meta']);
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => $running ? 'ACTIVE' : 'PAUSED', 'name' => $adName]);
    $material = AdMaterial::factory()->create(['title' => 'Eid reel', 'status' => 'live', 'product_id' => $product->id, 'media_buyer_id' => $buyer->id]);
    $material->ads()->attach($ad->id);

    return ['buyerUser' => $buyerUser, 'supervisor' => User::factory()->create(['role' => UserRole::Supervisor]), 'product' => $product, 'material' => $material, 'ad' => $ad];
}

function slNotes()
{
    return UserNotification::where('type', StockWatcher::LOW_NOTIFICATION_TYPE)->get();
}

it('flags a running material whose stock is at or under the threshold and tells the buyer and supervisors once, with the ad names', function () {
    $w = slWorld(3);

    expect(app(StockWatcher::class)->low())->toBe(['flagged' => 1, 'cleared' => 0]);

    expect($w['material']->fresh()->low_stock_at)->not->toBeNull()->and($w['material']->fresh()->need_stop_at)->toBeNull();
    expect(slNotes())->toHaveCount(2)->and(slNotes()->pluck('user_id')->sort()->values()->all())->toBe(collect([$w['buyerUser']->id, $w['supervisor']->id])->sort()->values()->all());
    $data = slNotes()->first()->data;
    expect($data['material_id'])->toBe($w['material']->id)->and($data['product_title'])->toBe('Abaya Noor')->and($data['inventory'])->toBe(3)
        ->and($data['threshold'])->toBe(10)->and($data['ads'])->toBe(['Eid reel A'])->and($data['link'])->toBe('/ads/decisions');

    expect(app(StockWatcher::class)->low())->toBe(['flagged' => 0, 'cleared' => 0])->and(slNotes())->toHaveCount(2);
});

it('is not low when the stock is above the threshold, out (that is need_stop), or nothing runs', function () {
    slWorld(11);
    slWorld(0);
    slWorld(2, running: false);

    expect(app(StockWatcher::class)->low())->toBe(['flagged' => 0, 'cleared' => 0])->and(slNotes())->toHaveCount(0);
});

it('clears the flag silently when stock comes back above the threshold, and a new episode notifies again', function () {
    $w = slWorld(3);
    $watcher = app(StockWatcher::class);
    $watcher->low();

    ProductVariant::where('product_id', $w['product']->id)->update(['inventory_quantity' => 40]);
    expect($watcher->low())->toBe(['flagged' => 0, 'cleared' => 1])->and($w['material']->fresh()->low_stock_at)->toBeNull()->and(slNotes())->toHaveCount(2);

    ProductVariant::where('product_id', $w['product']->id)->update(['inventory_quantity' => 1]);
    expect($watcher->low()['flagged'])->toBe(1)->and(slNotes())->toHaveCount(4);
});

it('reads the one low-stock number of the hub (launch checks, restock alerts and this decision share it)', function () {
    $w = slWorld(15);
    expect(app(StockWatcher::class)->low()['flagged'])->toBe(0);

    app(AdsSettings::class)->set(\App\Ads\Alerts\RuleSettings::LOW_STOCK_KEY, 20);
    expect(app(StockWatcher::class)->low()['flagged'])->toBe(1)->and(slNotes()->first()->data['threshold'])->toBe(20);
});

it('lists the running ads at stock risk for the decisions page, out-of-stock ones included', function () {
    $low = slWorld(2, adName: 'Low ad');
    $out = slWorld(0, adName: 'Out ad');
    slWorld(50, adName: 'Fine ad');
    $watcher = app(StockWatcher::class);
    $watcher->run();
    $watcher->low();

    $rows = collect($watcher->riskRows())->keyBy('ad_id');

    expect($rows)->toHaveCount(2)
        ->and($rows[$low['ad']->id]['stock'])->toBe('low')->and($rows[$low['ad']->id]['inventory'])->toBe(2)->and($rows[$low['ad']->id]['product'])->toBe('Abaya Noor')
        ->and($rows[$low['ad']->id]['material'])->toBe('Eid reel')->and($rows[$low['ad']->id]['account'])->toBe('LV Meta')
        ->and($rows[$out['ad']->id]['stock'])->toBe('out')->and($rows[$out['ad']->id]['inventory'])->toBe(0);
});

it('runs the low-stock check from ads:stock-watch and the Shopify stock job', function () {
    $w = slWorld(3);

    $this->artisan('ads:stock-watch')->assertSuccessful()->expectsOutputToContain('Low stock');
    expect($w['material']->fresh()->low_stock_at)->not->toBeNull();

    AdMaterial::query()->update(['low_stock_at' => null]);
    (new \App\Ads\Materials\Jobs\CheckProductStock([$w['product']->id]))->handle(app(StockWatcher::class));
    expect($w['material']->fresh()->low_stock_at)->not->toBeNull();
});
