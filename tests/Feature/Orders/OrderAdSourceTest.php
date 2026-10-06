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
