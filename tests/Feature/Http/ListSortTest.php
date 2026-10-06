<?php

use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\SupportCase;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function listSortIds($response, string $prop): array
{
    return collect($response->viewData('page')['props'][$prop]['data'])->pluck('id')->all();
}

it('sorts the orders list by a whitelisted column both ways', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $mid = Order::factory()->create(['total' => 200]);
    $low = Order::factory()->create(['total' => 100]);
    $high = Order::factory()->create(['total' => 300]);

    $asc = $this->actingAs($admin)->get('/orders?sort=total')->assertOk();
    expect(listSortIds($asc, 'orders'))->toBe([$low->id, $mid->id, $high->id])
        ->and($asc->viewData('page')['props']['filters']['sort'])->toBe('total');

    $desc = $this->actingAs($admin)->get('/orders?sort=-total')->assertOk();
    expect(listSortIds($desc, 'orders'))->toBe([$high->id, $mid->id, $low->id]);
});

it('keeps newest-first and a null sort for an unknown key', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    [$a, $b, $c] = Order::factory()->count(3)->create()->all();

    $response = $this->actingAs($admin)->get('/orders?sort=-password')->assertOk();
    expect(listSortIds($response, 'orders'))->toBe([$c->id, $b->id, $a->id])
        ->and($response->viewData('page')['props']['filters']['sort'])->toBeNull();
});

it('leaves the mobile API order list contract alone', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $first = Order::factory()->create(['total' => 300]);
    $second = Order::factory()->create(['total' => 100]);
    Sanctum::actingAs($admin);

    $ids = $this->getJson('/api/v1/orders?sort=total')->assertOk()->json('data.*.id');
    expect($ids)->toBe([$second->id, $first->id]);
});

it('sorts customers by order count', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $few = Customer::factory()->create(['orders_count' => 1]);
    $many = Customer::factory()->create(['orders_count' => 9]);

    $response = $this->actingAs($admin)->get('/customers?sort=-orders_count')->assertOk();
    expect(array_slice(listSortIds($response, 'customers'), 0, 2))->toBe([$many->id, $few->id])
        ->and($response->viewData('page')['props']['filters']['sort'])->toBe('-orders_count');
});

it('sorts cases oldest first on request', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $conversation = Conversation::factory()->create(['channel_account_id' => ChannelAccount::factory()->create(['platform' => Platform::Facebook])->id]);
    $old = SupportCase::factory()->create(['conversation_id' => $conversation->id, 'created_at' => now()->subDays(2)]);
    $new = SupportCase::factory()->create(['conversation_id' => $conversation->id, 'created_at' => now()]);

    $response = $this->actingAs($sup)->get('/cases?sort=date')->assertOk();
    expect(listSortIds($response, 'cases'))->toBe([$old->id, $new->id]);
});
