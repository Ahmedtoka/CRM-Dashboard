<?php

use App\Enums\UserRole;
use App\Models\User;

it('shares hosted Pusher settings (key + cluster, never the secret) when broadcasting via pusher', function () {
    config([
        'broadcasting.default' => 'pusher',
        'broadcasting.connections.pusher.key' => 'pk_live',
        'broadcasting.connections.pusher.secret' => 'shh-secret',
        'broadcasting.connections.pusher.options.cluster' => 'eu',
    ]);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->get('/inbox')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('broadcasting.driver', 'pusher')
            ->where('broadcasting.key', 'pk_live')
            ->where('broadcasting.cluster', 'eu'))
        ->assertDontSee('shh-secret');
});

it('shares no broadcasting settings when pusher has no key', function () {
    config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher.key' => null]);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->get('/inbox')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('broadcasting', null));
});
