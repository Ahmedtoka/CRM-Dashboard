<?php

use App\Analytics\PresenceTracker;
use App\Models\User;
use App\Models\UserSession;

it('merges heartbeats into sessions', function () {
    $u = User::factory()->create();
    $p = app(PresenceTracker::class);
    $p->heartbeat($u);
    $this->travel(3)->minutes();
    $p->heartbeat($u);
    $this->travel(10)->minutes();
    $p->heartbeat($u);
    expect(UserSession::where('user_id', $u->id)->count())->toBe(2)->and($p->onlineUserIds())->toContain($u->id);
});

it('closes the stale session and drops users without a recent heartbeat', function () {
    $u = User::factory()->create();
    $p = app(PresenceTracker::class);

    $p->heartbeat($u, 'mobile');
    $this->travel(3)->minutes();
    expect($p->onlineUserIds())->not->toContain($u->id);

    $this->travel(10)->minutes();
    $p->heartbeat($u);
    $first = UserSession::where('user_id', $u->id)->orderBy('id')->first();
    expect($first->ended_at)->not->toBeNull()->and($first->device)->toBe('mobile');

    $p->end($u);
    expect(UserSession::where('user_id', $u->id)->whereNull('ended_at')->count())->toBe(0)
        ->and($p->onlineUserIds())->not->toContain($u->id)
        ->and($u->fresh()->last_seen_at)->not->toBeNull();
});
