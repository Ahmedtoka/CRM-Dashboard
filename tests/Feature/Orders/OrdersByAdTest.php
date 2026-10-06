<?php

use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdCampaign;
use App\Models\AdSet;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC'));
    $campaign = AdCampaign::factory()->create(['name' => 'خريف']);
    $set = AdSet::factory()->create(['ad_campaign_id' => $campaign->id, 'name' => 'نساء ٢٥+']);
    $this->ad = Ad::factory()->create(['name' => 'اسدال كتان', 'ad_campaign_id' => $campaign->id, 'ad_set_id' => $set->id, 'thumbnail_url' => 'https://cdn.test/a.jpg']);
    $this->other = Ad::factory()->create(['name' => 'طرح']);
    $mk = function (array $a, int $qty = 1) {
        $o = Order::factory()->create($a + ['status' => OrderStatus::Confirmed, 'platform' => Platform::Facebook, 'placed_at' => '2026-10-05 10:00:00']);
        OrderItem::factory()->create(['order_id' => $o->id, 'title' => 'اسدال', 'qty' => $qty, 'price' => 100, 'image_url' => 'https://cdn.test/p.jpg']);

        return $o;
    };
    $mk(['ad_id' => $this->ad->id, 'total' => 300], 2);
    $mk(['ad_id' => $this->ad->id, 'total' => 200], 1);
    $mk(['ad_id' => $this->ad->id, 'total' => 999, 'status' => OrderStatus::Cancelled], 4);
    $mk(['ad_id' => $this->other->id, 'total' => 150], 1);
    $mk(['ad_id' => null, 'total' => 100], 1);
    $mk(['ad_id' => $this->ad->id, 'total' => 500, 'platform' => Platform::Instagram], 1);
});

it('groups the orders by ad with campaign, ad set and a direct row', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->get('/orders?tab=ads')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('tab', 'ads')->where('orders', null)->where('canOpenAds', true)
            ->where('adsBreakdown.0', [
                'ad_id' => $this->ad->id, 'ad' => 'اسدال كتان', 'thumbnail_url' => 'https://cdn.test/a.jpg', 'external_id' => (string) $this->ad->external_id,
                'platform' => 'meta', 'account_external_id' => $this->ad->account->external_id, 'campaign_id' => $this->ad->ad_campaign_id, 'campaign' => 'خريف', 'ad_set_id' => $this->ad->ad_set_id, 'ad_set' => 'نساء ٢٥+',
                'orders' => 4, 'revenue' => 1000, 'units' => 4,
            ])
            ->where('adsBreakdown', fn ($rows) => collect($rows)->firstWhere('ad_id', null)['platform'] === 'direct'
                && collect($rows)->firstWhere('ad_id', null)['orders'] === 1 && count($rows) === 3));
});

it('opens the ad orders page: summary, products and the orders list, role-scoped', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->get("/orders/ads/{$this->ad->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('Orders/AdOrders')
            ->where('ad.name', 'اسدال كتان')->where('ad.manager_url', 'https://www.facebook.com/adsmanager/manage/ads?act='.str_replace('act_', '', $this->ad->account->external_id)."&selected_ad_ids={$this->ad->external_id}")
            ->where('summary.orders', 4)->where('summary.real_orders', 3)->where('summary.revenue', 1000)->where('summary.units', 4)
            ->where('products.0', ['title' => 'اسدال', 'image_url' => 'https://cdn.test/p.jpg', 'units' => 4, 'revenue' => 400])
            ->where('orders.meta.total', 4));

    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Facebook]);
    $this->actingAs($mod)->get("/orders/ads/{$this->ad->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('summary.orders', 3)->where('canOpenAds', false)
            ->where('ad.name', 'اسدال كتان')->where('ad.external_id', null)->where('ad.manager_url', null));
    $this->actingAs($mod)->get('/orders?tab=ads')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('adsBreakdown.0.orders', 3));

    $this->actingAs($admin)->get('/orders/ads/999999')->assertNotFound();

    $buyer = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $this->actingAs($buyer)->get("/orders/ads/{$this->ad->id}")->assertRedirect();
});

it('404s an ad with no orders the non-supervisor can see, whatever the date range', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::WhatsApp]);

    // Only Facebook/Instagram orders carry this ad: invisible to a WhatsApp-only moderator.
    $this->actingAs($mod)->get("/orders/ads/{$this->ad->id}")->assertNotFound();
    $noOrders = Ad::factory()->create();
    $this->actingAs($mod)->get("/orders/ads/{$noOrders->id}")->assertNotFound();

    // A visible order outside the default month range still opens the page.
    $fb = User::factory()->create(['role' => UserRole::Moderator]);
    $fb->userPlatforms()->create(['platform' => Platform::Facebook]);
    Order::factory()->create(['ad_id' => $noOrders->id, 'platform' => Platform::Facebook, 'placed_at' => '2026-09-02 10:00:00']);
    $this->actingAs($fb)->get("/orders/ads/{$noOrders->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('summary.orders', 0)->where('ad.manager_url', null));

    // Supervisors open any ad, with its ids and link.
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $this->actingAs($sup)->get("/orders/ads/{$noOrders->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('ad.external_id', (string) $noOrders->external_id));
});
