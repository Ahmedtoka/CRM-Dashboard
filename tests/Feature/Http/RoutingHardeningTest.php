<?php

use App\Models\User;

it('does not start a session for inbound webhooks', function () {
    $response = $this->postJson('/webhooks/whatsapp', ['fake' => true, 'events' => []]);

    $response->assertOk();

    $cookies = collect($response->headers->getCookies())->map->getName();
    expect($cookies)->not->toContain(config('session.cookie'))
        ->and($cookies)->not->toContain('XSRF-TOKEN');
});

it('refuses broadcasting auth for a deactivated api token', function () {
    $u = User::factory()->create();
    $token = $u->createToken('phone')->plainTextToken;
    $u->forceFill(['is_active' => false])->save();

    $this->withToken($token)
        ->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'private-inbox'])
        ->assertUnauthorized();

    expect($u->tokens()->count())->toBe(0);
});

it('renders guest pages in the session locale', function () {
    $this->withSession(['locale' => 'en'])
        ->get('/login')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/Login')->where('locale', 'en'));
});

it('lets guests switch the language of the login page', function () {
    $this->from('/login')->post('/guest/locale/en')->assertRedirect('/login');

    expect(session('locale'))->toBe('en');

    $this->get('/login')->assertInertia(fn ($page) => $page->where('locale', 'en'));
});
