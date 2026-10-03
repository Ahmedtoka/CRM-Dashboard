<?php

use App\Ads\Commands\ImportArenaTokenCommand;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsOverview;
use App\Enums\UserRole;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdDailyMetric;
use App\Models\AdMaterial;
use App\Models\AdMaterialCollection;
use App\Models\AdMaterialFile;
use App\Models\AdPlatformConnection;
use App\Models\BuyerTarget;
use App\Models\Conversation;
use App\Models\MediaBuyer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AdsDemoSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('media');
    config(['crm.media.disk' => 'media', 'crm.ads.drivers.meta' => 'live']); // the seeder must force fake drivers itself

    $img = imagecreatetruecolor(40, 50);
    ob_start();
    imagejpeg($img);
    $jpeg = (string) ob_get_clean();
    Http::fake(['picsum.photos/*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg'])]);

    foreach (range(1, 4) as $i) {
        ProductVariant::factory()->create(['product_id' => Product::factory()->create()->id, 'inventory_quantity' => 10]);
    }
});

function seedAdsDemo(): void
{
    test()->seed(AdsDemoSeeder::class);
}

it('seeds buyers, accounts, assignments, targets, collections and materials, and re-runs without duplicates', function () {
    Order::factory()->count(3)->create(['placed_at' => now()->subDays(2)]);
    seedAdsDemo();
    $counts = fn () => [
        MediaBuyer::count(), User::where('role', UserRole::MediaBuyer)->count(), User::where('role', UserRole::Content)->count(),
        AdPlatformConnection::count(), AdAccount::count(), AdAccountAssignment::count(), BuyerTarget::count(),
        AdMaterialCollection::count(), AdMaterial::count(), AdMaterialFile::count(), AdDailyMetric::count(),
        Order::where('ad_attribution', 'utm_ad')->whereNotNull('ad_id')->count(),
        Conversation::whereNotNull('ad_id')->count(), Order::count(),
    ];
    $first = $counts();

    seedAdsDemo();

    expect($counts())->toBe($first)
        ->and($first[0])->toBe(3)->and($first[1])->toBe(3)->and($first[2])->toBe(1)
        ->and($first[3])->toBe(3)  // meta, tiktok, google (fake)
        ->and($first[4])->toBe(5)  // 3 meta + tiktok + google
        ->and($first[5])->toBe(5)  // Lv Main 22 has two rows (hand-over)
        ->and($first[6])->toBe(9)  // 3 buyers x 3 months
        ->and($first[7])->toBe(12)
        ->and($first[8])->toBe(30)
        ->and($first[9])->toBe(30)
        ->and($first[11])->toBe(3) // the demo orders got a utm ad attribution
        ->and($first[12])->toBe(25) // inbox conversations from the demo ads
        ->and((float) AdDailyMetric::sum('spend'))->toBeGreaterThan(0.0);

    // they land on the overview: conversations -> ordered both show numbers
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $totals = app(AdsOverview::class)->build(AdsFilter::fromRequest(Request::create('/ads'), $admin))['totals'];
    expect($totals['conversations'])->toBe(25)->and($totals['conversations_ordered'])->toBeGreaterThanOrEqual(5);

    $ahmed = MediaBuyer::whereHas('user', fn ($q) => $q->where('email', 'ahmed.gamal@crm.test'))->firstOrFail();
    $mostafa = MediaBuyer::whereHas('user', fn ($q) => $q->where('email', 'mostafa@crm.test'))->firstOrFail();
    $lv22 = AdAccount::where('external_id', AdsDemoSeeder::LV_MAIN_22)->firstOrFail();
    $today = CarbonImmutable::now('Africa/Cairo');
    expect($lv22->buyerOn($today->toDateString())?->id)->toBe($ahmed->id)
        ->and($lv22->buyerOn($today->subDays(40)->toDateString())?->id)->toBe($mostafa->id)
        ->and(AdAccount::where('external_id', AdsDemoSeeder::GOOGLE)->firstOrFail()->assignments()->count())->toBe(0);

    // Two activated materials are out of stock and flagged; some materials are linked to ads.
    expect(AdMaterial::whereNotNull('need_stop_at')->count())->toBe(2)
        ->and(AdMaterial::has('ads')->count())->toBeGreaterThan(0)
        ->and(AdMaterial::where('status', 'activated')->count())->toBeGreaterThan(0)
        ->and(AdMaterial::where('status', 'done')->count())->toBeGreaterThan(0)
        ->and(AdMaterial::where('status', 'not_started')->count())->toBeGreaterThan(0);
});

it('leaves a real Meta connection alone and creates no fake Meta connection next to it', function () {
    $live = AdPlatformConnection::create(['platform' => 'meta', 'name' => ImportArenaTokenCommand::CONNECTION_NAME, 'credentials' => ['access_token' => 'real'], 'status' => 'connected']);
    $account = AdAccount::create(['connection_id' => $live->id, 'platform' => 'meta', 'external_id' => AdsDemoSeeder::LV_MAIN, 'name' => 'Lv Main (تجريبي)']);

    seedAdsDemo();

    expect(AdPlatformConnection::where('platform', 'meta')->count())->toBe(1)
        ->and($account->fresh()->connection_id)->toBe($live->id)
        ->and(AdDailyMetric::where('ad_account_id', $account->id)->count())->toBe(0) // nothing fake written into the real account
        ->and($account->assignments()->count())->toBe(1)
        ->and(AdAccount::count())->toBe(3); // the real one + fake tiktok + google
});

it('brings old demo orders it attributes into the last 30 days, so the reports show real orders', function () {
    $old = Order::factory()->create(['placed_at' => now()->subDays(60)]);

    seedAdsDemo();
    $placed = $old->fresh()->placed_at;
    seedAdsDemo();

    expect($old->fresh()->ad_attribution)->toBe('utm_ad')
        ->and($old->fresh()->placed_at->greaterThan(now()->subDays(29)))->toBeTrue()
        ->and($old->fresh()->placed_at->equalTo($placed))->toBeTrue(); // stable on re-run
});
