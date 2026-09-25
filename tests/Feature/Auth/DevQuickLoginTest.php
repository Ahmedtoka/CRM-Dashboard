<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Onboarding\HomeRoute;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

it('shows the quick-login users on the login page when enabled locally', function () {
    config(['crm.dev_quick_login' => true]);
    $admin = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Admin']);
    User::factory()->create(['role' => UserRole::Moderator, 'is_active' => false]);

    $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page->component('auth/Login')
        ->has('quickUsers', 1)
        ->where('quickUsers.0.id', $admin->id)
        ->where('quickUsers.0.email', $admin->email));
});

it('signs a user in with one click when enabled', function () {
    config(['crm.dev_quick_login' => true]);
    $user = User::factory()->create(['role' => UserRole::Admin]);

    $this->post('/login/quick', ['user_id' => $user->id])->assertRedirect(HomeRoute::for($user)); // a fresh admin lands on «ابدأ من هنا»

    $this->assertAuthenticatedAs($user);
});

it('never exposes quick login when the switch is off', function () {
    config(['crm.dev_quick_login' => false]);
    $user = User::factory()->create(['role' => UserRole::Admin]);

    $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page->where('quickUsers', []));
    $this->post('/login/quick', ['user_id' => $user->id])->assertNotFound();

    $this->assertGuest();
});

it('never exposes quick login outside the local environment, even when the switch is on', function () {
    config(['crm.dev_quick_login' => true]);
    // A real server runs as production: both the panel and the route must be gone.
    // Leaving the testing environment also re-enables CSRF, hence the middleware skip.
    app()->detectEnvironment(fn () => 'production');
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $user = User::factory()->create(['role' => UserRole::Admin]);

    $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page->where('quickUsers', []));
    $this->post('/login/quick', ['user_id' => $user->id])->assertNotFound();

    $this->assertGuest();
});

it('refuses an inactive or unknown user', function () {
    config(['crm.dev_quick_login' => true]);
    $inactive = User::factory()->create(['role' => UserRole::Admin, 'is_active' => false]);

    $this->post('/login/quick', ['user_id' => $inactive->id])->assertSessionHasErrors('user_id');
    $this->post('/login/quick', ['user_id' => 999999])->assertSessionHasErrors('user_id');

    $this->assertGuest();
});
