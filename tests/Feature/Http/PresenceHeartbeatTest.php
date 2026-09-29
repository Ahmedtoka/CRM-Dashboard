<?php

use App\Analytics\PresenceTracker;
use App\Models\User;
use App\Models\UserSession;

it('refuses a heartbeat from a guest', function () {
    $this->postJson('/presence/heartbeat')->assertUnauthorized();

    expect(UserSession::count())->toBe(0);
});

it('marks the user online from the web heartbeat', function () {
    $u = User::factory()->create(['last_seen_at' => null]);
    $presence = app(PresenceTracker::class);
    expect($presence->isOnline($u))->toBeFalse();

    $this->actingAs($u)->postJson('/presence/heartbeat')
        ->assertOk()
        ->assertJsonPath('data.online_user_ids', [$u->id]);

    expect($presence->isOnline($u->fresh()))->toBeTrue()
        ->and(UserSession::where('user_id', $u->id)->whereNull('ended_at')->where('device', 'web')->count())->toBe(1);
});

it('keeps one session across heartbeats a minute apart and goes offline when they stop', function () {
    $u = User::factory()->create();
    $presence = app(PresenceTracker::class);

    $this->actingAs($u)->postJson('/presence/heartbeat')->assertOk();
    $this->travel(1)->minutes();
    $this->postJson('/presence/heartbeat')->assertOk();

    expect(UserSession::where('user_id', $u->id)->count())->toBe(1)
        ->and($presence->isOnline($u->fresh()))->toBeTrue();

    $this->travel(3)->minutes();

    expect($presence->isOnline($u->fresh()))->toBeFalse()
        ->and($presence->onlineUserIds())->not->toContain($u->id);
});

it('refuses a heartbeat from a deactivated user', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->postJson('/presence/heartbeat')->assertOk();

    $u->forceFill(['is_active' => false])->save();
    $this->travel(1)->minutes();
    $this->postJson('/presence/heartbeat')->assertUnauthorized();

    // The forced logout closes her session; no new one is opened by the refused heartbeat.
    expect(UserSession::where('user_id', $u->id)->whereNull('ended_at')->count())->toBe(0)
        ->and(app(PresenceTracker::class)->onlineUserIds())->not->toContain($u->id);
    $this->assertGuest();
});
