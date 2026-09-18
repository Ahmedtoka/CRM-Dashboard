<?php

use App\Commerce\FakeCommerceProvider;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\City;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

beforeEach(function () {
    Event::fake();
    FakeCommerceProvider::$payloads = [];
    FakeCommerceProvider::$forceError = null;

    $acc = ChannelAccount::factory()->create(['platform' => Platform::Instagram]);
    $this->conv = Conversation::factory()->for(Customer::factory())->for($acc, 'channelAccount')->create(['platform' => Platform::Instagram]);
    $this->variant = ProductVariant::factory()->for(Product::factory())->create(['price' => 500]);
    $this->city = City::factory()->create(['shipping_fee' => 60]);
    $this->mod = User::factory()->create(['role' => UserRole::Moderator]);
    $this->mod->userPlatforms()->create(['platform' => Platform::Instagram]);
});

function failedOrderId(User $creator): int
{
    FakeCommerceProvider::$forceError = 'Shopify timeout';

    $id = test()->actingAs($creator)->postJson('/inbox/conversations/'.test()->conv->id.'/orders', [
        'idempotency_key' => (string) Str::uuid(),
        'type' => 'cod',
        'items' => [['variant_id' => test()->variant->id, 'qty' => 1]],
        'shipping' => ['name' => 'Nour', 'phone' => '01001234567', 'city_id' => test()->city->id, 'address' => 'شارع النصر'],
    ])->assertJsonPath('data.status', 'failed')->json('data.id');

    FakeCommerceProvider::$forceError = null;

    return $id;
}

it('lets the creator retry a failed order from the web', function () {
    $id = failedOrderId($this->mod);

    $this->actingAs($this->mod)->postJson("/orders/{$id}/retry")
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.shipment.status', 'created');
});

it('forbids other moderators from retrying', function () {
    $id = failedOrderId($this->mod);
    $other = User::factory()->create(['role' => UserRole::Moderator]);
    $other->userPlatforms()->create(['platform' => Platform::Instagram]);

    $this->actingAs($other)->postJson("/orders/{$id}/retry")->assertForbidden();
});

it('lets supervisors retry over the api', function () {
    $id = failedOrderId($this->mod);
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);

    $this->actingAs($sup, 'sanctum')->postJson("/api/v1/orders/{$id}/retry")
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed');
});

it('refuses to retry an order that did not fail', function () {
    $id = failedOrderId($this->mod);
    $this->actingAs($this->mod)->postJson("/orders/{$id}/retry")->assertOk();

    $this->actingAs($this->mod)->postJson("/orders/{$id}/retry")->assertStatus(422);
});
