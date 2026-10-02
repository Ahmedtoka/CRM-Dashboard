<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
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

it('renders a cookie-less 404 statelessly: no sessions row, no session cookie', function () {
    config(['session.driver' => 'database']);
    app('session')->forgetDrivers();
    $cookie = (string) config('session.cookie');

    // Control: a normal page with the database driver does write a session row.
    $this->get('/login')->assertOk()->assertCookie($cookie);
    $rows = DB::table('sessions')->count();
    expect($rows)->toBeGreaterThan(0);

    $this->flushSession();
    $this->disableCookieEncryption();
    $response = $this->call('GET', '/nope', [], [], [], ['HTTP_ACCEPT' => 'text/html']);

    $response->assertNotFound()->assertCookieMissing($cookie)
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Error')->where('status', 404)->where('auth.user', null));
    expect(DB::table('sessions')->count())->toBe($rows);

    $this->call('POST', '/webhooks/nope')->assertNotFound()->assertCookieMissing($cookie);
    expect(DB::table('sessions')->count())->toBe($rows);
});

it('follows the browser language on the stateless 404 page', function () {
    $this->get('/nope', ['Accept-Language' => 'en-US,en;q=0.9'])
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Error')->where('locale', 'en'));
});

it('renders a 500 as the Error page without leaking the exception', function () {
    config(['app.debug' => false]);
    Route::middleware('web')->get('/__test/boom', fn () => throw new RuntimeException('secret-xyz'));

    $this->get('/__test/boom')
        ->assertStatus(500)
        ->assertDontSee('secret-xyz')
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Error')->where('status', 500));
});

it('keeps JSON for a 500 on a JSON request, without the exception text', function () {
    config(['app.debug' => false]);
    Route::middleware('web')->get('/__test/boom', fn () => throw new RuntimeException('secret-xyz'));

    $this->getJson('/__test/boom')
        ->assertStatus(500)
        ->assertJsonStructure(['message'])
        ->assertDontSee('secret-xyz');
});

it('renders a 503 as the Error page', function () {
    Route::middleware('web')->get('/__test/down', fn () => abort(503));

    $this->get('/__test/down')
        ->assertStatus(503)
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Error')->where('status', 503));
});
