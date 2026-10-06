<?php

use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC'));
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
});

/** Cairo is UTC+3 in October 2026 (DST): 2026-10-04 21:30 UTC is already 2026-10-05 in Cairo. */
function analyticsOrder(array $attrs, array $items = []): Order
{
    $order = Order::factory()->create($attrs + ['status' => OrderStatus::Confirmed, 'platform' => Platform::Facebook]);
    foreach ($items as [$title, $qty, $price]) {
        OrderItem::factory()->create(['order_id' => $order->id, 'title' => $title, 'qty' => $qty, 'price' => $price, 'image_url' => "https://cdn.test/{$title}.jpg"]);
    }

    return $order;
}

it('builds the analytics tab from grouped queries over the filtered range, on Cairo days', function () {
    $mona = Customer::factory()->create(['name' => 'منى']);
    $sara = Customer::factory()->create(['name' => 'سارة']);
    $old = Customer::factory()->create(['name' => 'قديمة']);

    // An order of `old` before the range makes her a repeat customer.
    analyticsOrder(['customer_id' => $old->id, 'placed_at' => '2026-09-20 10:00:00', 'total' => 100]);

    analyticsOrder(['customer_id' => $mona->id, 'placed_at' => '2026-10-04 21:30:00', 'total' => 500, 'shipping_province_code' => 'C', 'shipping_city' => 'مدينة نصر', 'delivered_at' => '2026-10-05 10:00:00'], [['اسدال', 2, 200]]);
    analyticsOrder(['customer_id' => $mona->id, 'placed_at' => '2026-10-05 08:00:00', 'total' => 300, 'shipping_province_code' => 'C', 'shipping_city' => 'مدينة نصر', 'fulfillment_status' => 'fulfilled'], [['اسدال', 1, 200], ['طرحة', 3, 30]]);
    analyticsOrder(['customer_id' => $sara->id, 'placed_at' => '2026-10-05 09:00:00', 'total' => 200, 'shipping_province_code' => 'GZ', 'shipping_city' => 'الدقي'], [['طرحة', 1, 30]]);
    analyticsOrder(['customer_id' => $old->id, 'placed_at' => '2026-10-06 09:00:00', 'total' => 400, 'shipping_province' => 'Alexandria', 'shipping_province_code' => null]);
    // Cancelled: counted as an order, never as revenue / customers / units.
    analyticsOrder(['customer_id' => $sara->id, 'placed_at' => '2026-10-05 10:00:00', 'total' => 999, 'status' => OrderStatus::Cancelled, 'cancelled_at' => now(), 'shipping_province_code' => 'GZ'], [['طرحة', 5, 30]]);
    // Outside the range (2026-10-04 20:59 UTC = 23:59 Cairo on the 4th).
    analyticsOrder(['customer_id' => $sara->id, 'placed_at' => '2026-10-04 20:59:00', 'total' => 777]);

    DB::enableQueryLog();
    $this->actingAs($this->admin)->get('/orders?tab=analytics&from=2026-10-05&to=2026-10-06')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('Orders/Index')->where('tab', 'analytics')->where('orders', null)
            ->where('analytics.totals', [
                'orders' => 5, 'real_orders' => 4, 'revenue' => 1400, 'aov' => 350, 'customers' => 3,
                'new_customers' => 1, 'repeat_customers' => 2, 'units' => 7,
            ])
            ->where('analytics.governorates.0', ['key' => 'C', 'orders' => 2, 'revenue' => 800, 'label' => 'القاهرة'])
            ->where('analytics.governorates.1', ['key' => 'GZ', 'orders' => 2, 'revenue' => 200, 'label' => 'الجيزة'])
            ->where('analytics.governorates.2.key', 'Alexandria')
            ->where('analytics.districts.0', ['key' => 'مدينة نصر', 'orders' => 2, 'revenue' => 800, 'label' => 'مدينة نصر'])
            ->where('analytics.frequency.one', 2)->where('analytics.frequency.two', 1)->where('analytics.frequency.three_plus', 0)
            ->where('analytics.frequency.customers.0.name', 'منى')
            ->where('analytics.products.0', ['title' => 'طرحة', 'image_url' => 'https://cdn.test/طرحة.jpg', 'units' => 4, 'revenue' => 120])
            ->where('analytics.products.1.units', 3)
            ->where('analytics.statuses', fn ($s) => collect($s)->pluck('orders', 'key')->sortKeys()->all() === ['cancelled' => 1, 'confirmed' => 2, 'delivered' => 1, 'shipped' => 1])
            ->where('analytics.days', [
                ['date' => '2026-10-05', 'orders' => 4, 'revenue' => 1000],
                ['date' => '2026-10-06', 'orders' => 1, 'revenue' => 400],
            ]));

    // Grouped SQL: the analytics queries do not grow with the number of orders.
    $orderQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'orders'))->count();
    expect($orderQueries)->toBeLessThan(20);
});

it('scopes the analytics to what a moderator sees, like the list', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Instagram]);
    analyticsOrder(['platform' => Platform::Instagram, 'placed_at' => '2026-10-05 10:00:00', 'total' => 100]);
    analyticsOrder(['platform' => Platform::Facebook, 'placed_at' => '2026-10-05 10:00:00', 'total' => 900]);

    $this->actingAs($mod)->get('/orders?tab=analytics&from=2026-10-05&to=2026-10-05')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('analytics.totals.orders', 1)->where('analytics.totals.revenue', 100));
});

it('defaults the page to this Cairo month and filters by governorate and ad platform', function () {
    analyticsOrder(['placed_at' => '2026-10-02 10:00:00', 'shipping_province_code' => 'C']);
    analyticsOrder(['placed_at' => '2026-10-02 10:00:00', 'shipping_province_code' => 'GZ']);
    analyticsOrder(['placed_at' => '2026-09-25 10:00:00', 'shipping_province_code' => 'C']);
    $ad = Ad::factory()->create();
    analyticsOrder(['placed_at' => '2026-10-03 10:00:00', 'shipping_province_code' => 'C', 'ad_id' => $ad->id]);

    $this->actingAs($this->admin)->get('/orders')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('tab', 'list')->where('range', ['from' => '2026-10-01', 'to' => '2026-10-06'])
            ->where('orders.meta.total', 3));

    $this->actingAs($this->admin)->get('/orders?governorate=C')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('orders.meta.total', 2));
    $this->actingAs($this->admin)->get('/orders?ad_platform=direct')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('orders.meta.total', 2));
    $this->actingAs($this->admin)->get('/orders?ad_platform=meta')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('orders.meta.total', 1)
            ->where('orders.data.0.governorate', 'القاهرة')
            ->where('orders.data.0.ad_source.platform', 'meta')
            ->where('orders.data.0.ad_source.manager_url', "https://www.facebook.com/adsmanager/manage/ads?selected_ad_ids={$ad->external_id}"));

    // The JSON list (Today links, widgets) keeps no default range.
    $this->actingAs($this->admin)->getJson('/orders')->assertOk()->assertJsonPath('meta.total', 4);
});
