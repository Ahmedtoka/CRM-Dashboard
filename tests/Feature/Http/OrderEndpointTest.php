<?php

use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\City;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['crm.dev_tools' => true]); // one test pays through the simulator
    Event::fake();
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Instagram]);
    $this->conv = Conversation::factory()->for(Customer::factory())->for($acc, 'channelAccount')->create(['platform' => Platform::Instagram]);
    $this->variant = ProductVariant::factory()->for(Product::factory())->create(['price' => 500]);
    $this->city = City::factory()->create(['shipping_fee' => 60]);
});

it('creates a cod order from the inbox', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);

    $res = $this->actingAs($sup)->postJson("/inbox/conversations/{$this->conv->id}/orders", [
        'idempotency_key' => (string) Str::uuid(),
        'type' => 'cod',
        'items' => [['variant_id' => $this->variant->id, 'qty' => 2]],
        'shipping' => ['name' => 'Nour', 'phone' => '01001234567', 'city_id' => $this->city->id, 'address' => '12 شارع النصر'],
        'discount' => 0,
    ]);

    expect($res->status())->toBeIn([200, 201]);
    $res->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.created_by.id', $sup->id)
        ->assertJsonPath('data.items.0.qty', 2)
        ->assertJsonPath('data.shipment.status', 'created');
});

it('rejects moderator discounts with 403 and empty items with 422', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Instagram]);
    $url = "/inbox/conversations/{$this->conv->id}/orders";

    $key = (string) Str::uuid();
    $this->actingAs($mod)->postJson($url, ['idempotency_key' => $key, 'type' => 'cod', 'items' => [['variant_id' => $this->variant->id, 'qty' => 1]], 'discount' => 50])->assertForbidden();
    $this->actingAs($mod)->postJson($url, ['idempotency_key' => (string) Str::uuid(), 'type' => 'cod', 'items' => []])->assertStatus(422);
});

it('refuses to mark a non-awaiting order paid', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $order = Order::factory()->create(['status' => OrderStatus::Cancelled, 'platform' => Platform::Instagram]);

    $this->actingAs($sup)->postJson("/orders/{$order->id}/mark-paid")->assertStatus(422)->assertJsonStructure(['message']);
    $this->actingAs($admin)->postJson("/simulator/orders/{$order->id}/pay")->assertStatus(422)->assertJsonStructure(['message']);
    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)->and($order->fresh()->paid_at)->toBeNull();
});

it('rejects unknown variants with 422', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $this->actingAs($sup)->postJson("/inbox/conversations/{$this->conv->id}/orders", ['type' => 'cod', 'items' => [['variant_id' => 999999, 'qty' => 1]]])
        ->assertStatus(422)->assertJsonValidationErrors('items.0.variant_id');
});

it('lets supervisors mark paid and ship, but not moderators', function () {
    config(['crm.auto_create_shipment' => false]);
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $order = $this->actingAs($sup)->postJson("/inbox/conversations/{$this->conv->id}/orders", [
        'idempotency_key' => (string) Str::uuid(),
        'type' => 'payment_link', 'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
    ])->assertJsonPath('data.status', 'awaiting_payment')->json('data.id');

    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Instagram]);
    $this->actingAs($mod)->postJson("/orders/{$order}/mark-paid")->assertForbidden();

    $this->actingAs($sup)->postJson("/orders/{$order}/mark-paid")->assertOk()->assertJsonPath('data.status', 'confirmed');
    $this->actingAs($sup)->postJson("/orders/{$order}/ship")->assertOk()->assertJsonPath('data.shipment.status', 'created');
});
