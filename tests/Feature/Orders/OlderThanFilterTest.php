<?php

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-06 12:00', 'Africa/Cairo')));

it('lists only orders created at least N minutes ago', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $old = Order::factory()->create(['status' => OrderStatus::AwaitingPayment, 'created_at' => now()->subHours(3)]);
    Order::factory()->create(['status' => OrderStatus::AwaitingPayment, 'created_at' => now()->subHour()]);

    $this->actingAs($sup)->getJson('/orders?status=awaiting_payment&older_than=120')->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $old->id);
    $this->actingAs($sup)->get('/orders?older_than=120')->assertOk()
        ->assertInertia(fn ($p) => $p->where('filters.older_than', '120'));
});

it('validates older_than', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);

    $this->actingAs($sup)->getJson('/orders?older_than=0')->assertUnprocessable();
    $this->actingAs($sup)->getJson('/orders?older_than=abc')->assertUnprocessable();
});

it('keeps real and step dates web only: the api ignores them', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    Order::factory()->create(['status' => OrderStatus::Cancelled]);
    Order::factory()->create(['status' => OrderStatus::Confirmed]);

    $this->actingAs($sup)->getJson('/orders?real=1')->assertOk()->assertJsonPath('meta.total', 1);
    auth()->forgetGuards();
    $token = $sup->createToken('t')->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/orders?real=1&step_from=bad')->assertOk()->assertJsonPath('meta.total', 2);
});
