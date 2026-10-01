<?php

use App\Analytics\MetricsService;
use App\Enums\UserRole;
use App\Models\QueueSetting;
use App\Models\User;
use App\Onboarding\HomeRoute;
use App\Queue\BoardState;
use App\Queue\ShiftService;
use Laravel\Sanctum\Sanctum;


it('sends a media buyer away from the inbox to the ads area', function () {
    $buyer = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $this->actingAs($buyer)->get('/inbox')->assertRedirect('/ads');
});

it('sends a content user to the materials library', function () {
    $content = User::factory()->create(['role' => UserRole::Content]);
    $this->actingAs($content)->get('/orders')->assertRedirect('/ads/materials');
});

it('lets ads roles into their own area', function () {
    $buyer = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $this->actingAs($buyer)->get('/ads')->assertOk();
    $this->actingAs($buyer)->get('/ads/materials')->assertOk();
});

it('refuses json actions outside ads for ads roles', function () {
    $buyer = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $this->actingAs($buyer)->postJson('/presence/heartbeat')->assertForbidden();
});

it('refuses the queue check-in for ads roles', function () {
    $buyer = User::factory()->create(['role' => UserRole::Content]);
    $this->actingAs($buyer)->postJson('/queue/me/check-in')->assertForbidden();
});

it('keeps ads roles off the live board roster', function () {
    QueueSetting::current()->update(['enabled' => true]);
    User::factory()->create(['role' => UserRole::MediaBuyer, 'name' => 'Buyer Person']);
    User::factory()->create(['role' => UserRole::Admin, 'name' => 'Admin Person']);
    $state = app(BoardState::class)->snapshot();
    expect(collect($state['users'])->pluck('name'))->toContain('Admin Person')->not->toContain('Buyer Person');
});

it('lets staff keep using the inbox', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $this->withoutVite()->actingAs($mod)->get('/inbox')->assertOk();
});

it('classifies roles', function () {
    expect(User::factory()->make(['role' => UserRole::MediaBuyer])->isAdsRole())->toBeTrue()
        ->and(User::factory()->make(['role' => UserRole::Moderator])->isInboxStaff())->toBeTrue()
        ->and(HomeRoute::for(User::factory()->make(['role' => UserRole::Content])))->toBe('/ads/materials');
});

it('lets an admin create a media buyer user', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($admin)->post('/settings/users', [
        'name' => 'Ahmed Gamal', 'email' => 'ag@test.local', 'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123', 'role' => 'media_buyer',
    ])->assertSessionHasNoErrors();
    expect(User::where('email', 'ag@test.local')->first()->role)->toBe(UserRole::MediaBuyer);
});

it('refuses ads roles at the mobile api login', function () {
    User::factory()->create(['role' => UserRole::MediaBuyer, 'email' => 'b@test.local', 'password' => 'secret-pass-123']);
    $this->postJson('/api/v1/auth/login', ['email' => 'b@test.local', 'password' => 'secret-pass-123', 'device_name' => 'x'])
        ->assertStatus(422)->assertJsonValidationErrors('email');
});

it('rejects an ads role token already issued', function () {
    Sanctum::actingAs(User::factory()->create(['role' => UserRole::Content]));
    $this->getJson('/api/v1/me')->assertForbidden();
});

it('keeps ads roles out of the inbox staff scope', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $buyer = User::factory()->create(['role' => UserRole::MediaBuyer]);
    expect(User::query()->inboxStaff()->pluck('id'))->toContain($mod->id)->not->toContain($buyer->id);
});

it('leaves ads roles out of the team report leaderboard', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $buyer = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $ids = collect(app(MetricsService::class)->leaderboard(now()->subDay(), now()))->map(fn ($r) => $r['user']['id'] ?? null)->all();
    expect($ids)->toContain($mod->id)->not->toContain($buyer->id);
});

it('cannot check an ads role into a shift, even with a platform', function () {
    $buyer = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $buyer->userPlatforms()->create(['platform' => 'facebook']);
    expect(ShiftService::mayCheckIn($buyer))->toBeFalse();
});

it('refuses an ads role as a shift leader', function () {
    $buyer = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($admin)->put('/settings/queue', ['shifts' => [['key' => 'morning', 'name' => 'x', 'from' => '10:00', 'to' => '18:00', 'location' => 'office', 'leader_user_id' => $buyer->id]]])
        ->assertSessionHasErrors('shifts.0.leader_user_id');
});

it('lets an ads role reach notifications and refuses a plain post outside ads', function () {
    $buyer = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $this->actingAs($buyer)->get('/notifications')->assertOk();
    $this->actingAs($buyer)->post('/locale/en')->assertForbidden();
});
