<?php

use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The mobile app's own order-creation endpoint (fix round 1, item c): it shares
 * `ConversationEndpoints::storeOrder` with the web drawer. Final fix wave I4:
 * API v1 accepts a missing `idempotency_key` for one release (server UUID + a
 * warning log) until the updated mobile app is deployed; the web drawer keeps
 * it required, and a present key must still be a uuid.
 */
beforeEach(function () {
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Instagram]);
    $this->conv = Conversation::factory()->for(Customer::factory())->for($acc, 'channelAccount')->create(['platform' => Platform::Instagram]);
    $this->variant = ProductVariant::factory()->for(Product::factory())->create(['price' => 500]);
    $this->user = User::factory()->create(['role' => UserRole::Supervisor]);
    $this->token = $this->user->createToken('test')->plainTextToken;
});

it('creates an order without an idempotency key via the mobile api and logs one warning', function () {
    $logger = Mockery::mock(\Psr\Log\LoggerInterface::class);
    $logger->shouldReceive('warning')->once()->with('api_v1_order_without_idempotency_key', ['user_id' => $this->user->id]);
    Log::partialMock()->shouldReceive('channel')->with('stack')->once()->andReturn($logger);

    $id = $this->withToken($this->token)->postJson("/api/v1/conversations/{$this->conv->id}/orders", [
        'type' => 'cod',
        'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        'shipping' => ['name' => 'Nour', 'phone' => '01001234567', 'address' => 'شارع النصر'],
    ])->assertCreated()->json('data.id');

    expect(Str::isUuid((string) Order::find($id)->idempotency_key))->toBeTrue();
});

it('rejects an invalid idempotency key via the mobile api', function () {
    $this->withToken($this->token)->postJson("/api/v1/conversations/{$this->conv->id}/orders", [
        'idempotency_key' => 'not-a-uuid',
        'type' => 'cod',
        'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        'shipping' => ['name' => 'Nour', 'phone' => '01001234567', 'address' => 'شارع النصر'],
    ])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');

    expect(Order::count())->toBe(0);
});

it('still requires an idempotency key on the web drawer route', function () {
    $this->actingAs($this->user)->postJson("/inbox/conversations/{$this->conv->id}/orders", [
        'type' => 'cod',
        'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        'shipping' => ['name' => 'Nour', 'phone' => '01001234567', 'address' => 'شارع النصر'],
    ])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');

    expect(Order::count())->toBe(0);
});

it('replays the same order for a repeated api idempotency key instead of creating a second one', function () {
    $payload = [
        'idempotency_key' => (string) Str::uuid(),
        'type' => 'cod',
        'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        'shipping' => ['name' => 'Nour', 'phone' => '01001234567', 'address' => 'شارع النصر'],
    ];

    $firstId = $this->withToken($this->token)->postJson("/api/v1/conversations/{$this->conv->id}/orders", $payload)
        ->assertSuccessful()->json('data.id');

    $secondResponse = $this->withToken($this->token)->postJson("/api/v1/conversations/{$this->conv->id}/orders", $payload)
        ->assertSuccessful();

    expect($secondResponse->json('data.id'))->toBe($firstId)
        ->and(Order::query()->where('idempotency_key', $payload['idempotency_key'])->count())->toBe(1);
});
