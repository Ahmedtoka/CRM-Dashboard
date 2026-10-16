<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserNotification;
use Laravel\Sanctum\Sanctum;

/* The bell on the mobile API (/api/v1/notifications): the web payload, plus before_id paging. */

it('lists only her own notifications, newest first, with the badges', function () {
    $u = User::factory()->create(['role' => UserRole::Moderator]);
    $mine = UserNotification::factory()->count(3)->create(['user_id' => $u->id]);
    UserNotification::factory()->create(['user_id' => $u->id, 'read_at' => now()]);
    UserNotification::factory()->create(); // somebody else's
    Sanctum::actingAs($u);

    $res = $this->getJson('/api/v1/notifications')->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('unread_notifications', 3)
        ->assertJsonPath('unread_conversations', 0)
        ->assertJsonStructure(['data' => [['id', 'type', 'data', 'read_at', 'created_at']]]);

    expect(collect($res->json('data'))->pluck('id')->all())->toBe($u->userNotifications()->orderByDesc('id')->pluck('id')->all())
        ->and($res->json('data.3.id'))->toBe($mine->first()->id);
    expect($res->json())->toBe($this->actingAs($u, 'web')->getJson('/notifications')->json());
});

it('pages back 30 at a time with before_id', function () {
    $u = User::factory()->create(['role' => UserRole::Moderator]);
    $all = UserNotification::factory()->count(35)->create(['user_id' => $u->id])->pluck('id')->sortDesc()->values();
    Sanctum::actingAs($u);

    $first = $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(30, 'data')->json('data');
    $lastId = end($first)['id'];

    $next = $this->getJson("/api/v1/notifications?before_id={$lastId}")->assertOk()->assertJsonCount(5, 'data')->json('data');
    expect(collect($next)->pluck('id')->all())->toBe($all->slice(30)->values()->all());

    $this->getJson('/api/v1/notifications?before_id=abc')->assertUnprocessable()->assertJsonValidationErrors(['before_id']);
});

it('marks some or all of her notifications read, never anyone else\'s', function () {
    $u = User::factory()->create(['role' => UserRole::Moderator]);
    [$a, $b, $c] = UserNotification::factory()->count(3)->create(['user_id' => $u->id])->all();
    $other = UserNotification::factory()->create();
    Sanctum::actingAs($u);

    $this->postJson('/api/v1/notifications/read', ['ids' => [$a->id, $other->id]])->assertOk()->assertJsonPath('unread_notifications', 2);
    expect($a->fresh()->read_at)->not->toBeNull()->and($other->fresh()->read_at)->toBeNull();

    $this->postJson('/api/v1/notifications/read')->assertOk()->assertJsonPath('unread_notifications', 0);
    expect($b->fresh()->read_at)->not->toBeNull()->and($c->fresh()->read_at)->not->toBeNull()->and($other->fresh()->read_at)->toBeNull();

    $this->postJson('/api/v1/notifications/read', ['ids' => ['x']])->assertUnprocessable();
});

it('refuses ads roles, inactive users and missing tokens', function () {
    $this->getJson('/api/v1/notifications')->assertUnauthorized();

    Sanctum::actingAs(User::factory()->create(['role' => UserRole::MediaBuyer]));
    $this->getJson('/api/v1/notifications')->assertForbidden();
    $this->postJson('/api/v1/notifications/read')->assertForbidden();
    app('auth')->forgetGuards();

    $inactive = User::factory()->create(['role' => UserRole::Moderator, 'is_active' => false]);
    $this->withToken($inactive->createToken('phone')->plainTextToken)->getJson('/api/v1/notifications')->assertUnauthorized();
});
