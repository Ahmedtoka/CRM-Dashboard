<?php

use App\Enums\{Platform, UserRole};
use App\Models\{Order, User};
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $this->mona = User::factory()->create(['role' => UserRole::Moderator, 'name' => 'Mona']);
    $this->omar = User::factory()->create(['role' => UserRole::Moderator, 'name' => 'Omar']);

    $at = fn (string $utc) => CarbonImmutable::parse($utc, 'UTC');

    // 22:30 UTC on the 9th is already the 10th in Cairo.
    $this->lateNight = Order::factory()->create(['created_by_id' => $this->mona->id, 'platform' => Platform::Instagram, 'created_at' => $at('2026-09-09 22:30:00')]);
    $this->noon = Order::factory()->create(['created_by_id' => $this->omar->id, 'platform' => Platform::Instagram, 'created_at' => $at('2026-09-10 12:00:00')]);
    $this->older = Order::factory()->create(['created_by_id' => $this->mona->id, 'platform' => Platform::Instagram, 'created_at' => $at('2026-09-08 10:00:00')]);
});

function orderIds($response): array
{
    return collect($response->json('data'))->pluck('id')->sort()->values()->all();
}

it('filters orders by the team member who created them', function () {
    $ids = orderIds($this->actingAs($this->sup, 'sanctum')->getJson("/api/v1/orders?created_by={$this->mona->id}")->assertOk());

    expect($ids)->toBe(collect([$this->lateNight->id, $this->older->id])->sort()->values()->all());
});

it('filters orders by Cairo calendar dates', function () {
    $ids = orderIds($this->actingAs($this->sup, 'sanctum')->getJson('/api/v1/orders?from=2026-09-10&to=2026-09-10')->assertOk());

    expect($ids)->toBe(collect([$this->lateNight->id, $this->noon->id])->sort()->values()->all());

    $untilNinth = orderIds($this->actingAs($this->sup, 'sanctum')->getJson('/api/v1/orders?to=2026-09-09')->assertOk());
    expect($untilNinth)->toBe([$this->older->id]);
});

it('rejects malformed dates', function () {
    $this->actingAs($this->sup, 'sanctum')->getJson('/api/v1/orders?from=10-09-2026')->assertStatus(422);
});

it('shares the new filters and the team list with the orders page', function () {
    $this->actingAs($this->sup)
        ->get("/orders?created_by={$this->omar->id}&from=2026-09-10")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Orders/Index')
            ->where('filters.created_by', (string) $this->omar->id)
            ->where('filters.from', '2026-09-10')
            ->where('filters.to', null)
            ->has('orders.data', 1)
            ->has('team', 3));
});
