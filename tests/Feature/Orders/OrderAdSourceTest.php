<?php

use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdCampaign;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => $this->withoutVite());

it('shows the ad source on the order list and the customer page', function () {
    $campaign = AdCampaign::factory()->create(['name' => 'خريف 2026']);
    $ad = Ad::factory()->create(['name' => 'اسدال كتان', 'ad_campaign_id' => $campaign->id, 'thumbnail_url' => 'https://cdn.test/a.jpg']);
    $customer = Customer::factory()->create();
    Order::factory()->create(['customer_id' => $customer->id, 'ad_id' => $ad->id, 'ad_attribution' => 'utm_ad', 'status' => 'confirmed']);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->get('/orders')->assertOk()
        ->assertInertia(fn ($page) => $page->where('orders.data.0.ad_source', [
            'id' => $ad->id, 'name' => 'اسدال كتان', 'thumbnail_url' => 'https://cdn.test/a.jpg', 'campaign' => 'خريف 2026', 'attribution' => 'utm_ad',
            'platform' => 'meta', 'external_id' => (string) $ad->external_id,
            'manager_url' => 'https://www.facebook.com/adsmanager/manage/ads?act='.str_replace('act_', '', $ad->account->external_id)."&selected_ad_ids={$ad->external_id}",
        ]));

    $this->actingAs($admin)->get("/customers/{$customer->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('customer.orders.0.ad_source.name', 'اسدال كتان'));
});

it('says direct (null) for an order without an ad on the web list', function () {
    Order::factory()->create(['customer_id' => Customer::factory()->create()->id, 'status' => 'confirmed']);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get('/orders')->assertOk()
        ->assertInertia(fn ($page) => $page->where('orders.data.0.ad_source', null));
});

it('never adds ad_source to the mobile API', function () {
    $ad = Ad::factory()->create();
    $customer = Customer::factory()->create();
    $order = Order::factory()->create(['customer_id' => $customer->id, 'ad_id' => $ad->id, 'status' => 'confirmed']);
    Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

    $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->assertJsonMissingPath('data.ad_source');
    $this->getJson('/api/v1/orders')->assertOk()->assertJsonMissingPath('data.0.ad_source');
});

it('shows the ad source on the order page, and only supervisors and admins may open the ad', function () {
    $ad = Ad::factory()->create(['name' => 'خمار شيفون']);
    $order = Order::factory()->create(['customer_id' => Customer::factory()->create()->id, 'ad_id' => $ad->id, 'status' => 'confirmed']);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get("/orders/{$order->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('Orders/Show')->where('order.ad_source.name', 'خمار شيفون')->where('canOpenAds', true));

    $direct = Order::factory()->create(['customer_id' => Customer::factory()->create()->id, 'status' => 'confirmed']);
    $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]))->get("/orders/{$direct->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('order.ad_source', null)->where('canOpenAds', true));

    // A moderator sees where her order came from, but the chip does not open the ad.
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => \App\Enums\Platform::Facebook]);
    $own = Order::factory()->create(['customer_id' => Customer::factory()->create()->id, 'ad_id' => $ad->id, 'status' => 'confirmed', 'platform' => 'facebook', 'created_by_id' => $mod->id]);
    $this->actingAs($mod)->get("/orders/{$own->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('order.ad_source.name', 'خمار شيفون')->where('canOpenAds', false));
});
