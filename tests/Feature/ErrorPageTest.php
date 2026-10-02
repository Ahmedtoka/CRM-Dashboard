<?php

use App\Enums\UserRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('renders the Inertia Error page for an unknown address, inside the signed-in app', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($user)->get('/does-not-exist')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Error')
            ->where('status', 404)
            ->where('auth.user.id', $user->id));
});

it('answers an Inertia visit to an unknown address with the Error page, not a full HTML error', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);

    $version = $this->actingAs($user)->get('/does-not-exist')->viewData('page')['version'];

    $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version, 'X-Requested-With' => 'XMLHttpRequest'])
        ->get('/does-not-exist')
        ->assertNotFound()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'Error')
        ->assertJsonPath('props.status', 404);
});

it('renders the Error page for a forbidden page', function () {
    $moderator = User::factory()->create(['role' => UserRole::Moderator]);

    $this->actingAs($moderator)->get('/onboarding')
        ->assertForbidden()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Error')->where('status', 403));
});

it('keeps JSON for JSON requests', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($user)->getJson('/does-not-exist')
        ->assertNotFound()
        ->assertJsonStructure(['message']);

    $moderator = User::factory()->create(['role' => UserRole::Moderator]);
    $this->actingAs($moderator)->getJson('/onboarding')
        ->assertForbidden()
        ->assertJsonStructure(['message']);
});

it('shows a guest the Error page too, without the app shell', function () {
    $this->get('/does-not-exist')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Error')
            ->where('status', 404)
            ->where('auth.user', null));
});

it('keeps a POST to an unknown address a 404, not a 405 or a 419', function () {
    $this->post('/does-not-exist')->assertNotFound();
    $this->postJson('/webhooks/does-not-exist')->assertNotFound()->assertJsonStructure(['message']);
});

it('keeps a 405 for a known address called with the wrong verb', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($user)->delete('/settings/profile')->assertStatus(405);
});
