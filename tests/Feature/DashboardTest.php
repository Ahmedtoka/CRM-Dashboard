<?php

use App\Enums\UserRole;
use App\Models\BotSetting;
use App\Models\MediaBuyer;
use App\Models\User;

/**
 * `/`, `/dashboard` and the login all land on HomeRoute: «النهارده» for admins and supervisors (control room S4),
 * the inbox for agents, the Ads Hub for the ads roles, guests on the login page.
 */
beforeEach(fn () => BotSetting::current()->update(['onboarding_dismissed_at' => now()])); // a fresh admin would land on «ابدأ من هنا»

it('sends guests to the login page', function () {
    $this->get('/dashboard')->assertRedirect('/login');
    $this->get('/')->assertRedirect('/login');
});

it('sends each role to its home', function (UserRole $role, string $home) {
    $u = User::factory()->create(['role' => $role, 'password' => 'password']);
    if ($role === UserRole::MediaBuyer) {
        MediaBuyer::factory()->create(['user_id' => $u->id]);
    }
    $target = route($home, absolute: false);

    $this->actingAs($u)->get('/')->assertRedirect($target);
    $this->actingAs($u)->get('/dashboard')->assertRedirect($target);
    auth()->logout();
    $this->post('/login', ['email' => $u->email, 'password' => 'password'])->assertRedirect($target);
})->with([
    'admin' => [UserRole::Admin, 'today'],
    'supervisor' => [UserRole::Supervisor, 'today'],
    'agent' => [UserRole::Moderator, 'inbox'],
    'content' => [UserRole::Content, 'ads.materials.index'],
]);

it('sends a media buyer to the ads hub, never to /today', function () {
    $u = User::factory()->create(['role' => UserRole::MediaBuyer]);
    MediaBuyer::factory()->create(['user_id' => $u->id]);

    $location = $this->actingAs($u)->get('/dashboard')->headers->get('Location');
    expect($location)->toContain('/ads')->not->toContain('/today');
});
