<?php

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Shipping\ShipmentService;
use App\Shopify\Connection\ShopifyIntegration;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

// Fresh-orders F4: the CRM no longer ships or tracks shipments; Shopify is the only delivery source.

it('has no ship action, simulator shipment advance or shipment tables any more', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed]);

    $this->actingAs($admin)->postJson("/orders/{$order->id}/ship")->assertNotFound();
    $this->actingAs($admin)->postJson('/simulator/shipments/1/advance')->assertNotFound();

    expect(Route::has('orders.ship'))->toBeFalse()
        ->and(Route::has('simulator.shipments.advance'))->toBeFalse()
        ->and(Schema::hasTable('shipments'))->toBeFalse()
        ->and(Schema::hasTable('shipment_events'))->toBeFalse()
        ->and(class_exists(ShipmentService::class))->toBeFalse()
        ->and(class_exists(Shipment::class))->toBeFalse();
});

it('keeps every api route the mobile app calls, with shipment null in the order payload', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'shipment_status' => 'in_transit']);
    Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

    $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('data.0.shipment', null)
        ->assertJsonPath('data.0.display.shipment_step', 'in_transit');
    $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->assertJsonPath('data.shipment', null);
    $this->getJson('/api/v1/shipping/provinces')->assertOk();
    $this->getJson('/api/v1/shipping/quote?subtotal=100')->assertOk();
    $this->getJson('/api/v1/cities')->assertOk();
    $this->getJson('/api/v1/products')->assertOk();

    foreach (['api.v1.conversations.orders.store', 'api.v1.orders.retry', 'api.v1.shipping.quote', 'api.v1.shipping.provinces', 'api.v1.cities.index'] as $name) {
        expect(Route::has($name))->toBeTrue($name);
    }
});

it('drops the auto shipment setting from the Shopify settings', function () {
    expect(config('crm.auto_create_shipment'))->toBeNull()
        ->and((new ShopifyIntegration)->settingsWithDefaults())->not->toHaveKey('auto_create_shipment');
});
