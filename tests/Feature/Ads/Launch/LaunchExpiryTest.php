<?php

use App\Ads\Launch\LaunchSettings;
use App\Ads\Launch\LaunchState;
use App\Enums\UserRole;
use App\Models\AdAccountAssignment;
use App\Models\AdPublication;
use App\Models\MediaBuyer;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Console\Scheduling\Schedule;
use Tests\Support\LaunchWorld;

beforeEach(fn () => LaunchWorld::boot());

it('TC-06: expires a launch past its deadline, archives its paused ads and tells content and the buyer', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::AwaitingApproval, ['expires_at' => now()->subMinute()]);

    $this->artisan('ads:launch-sweep')->expectsOutput('Warned 0, expired 1, reassigned 0.')->assertSuccessful();

    expect($l->fresh()->state)->toBe(LaunchState::Expired)
        ->and(AdPublication::where('ad_launch_id', $l->id)->whereNull('archived_at')->count())->toBe(0)
        ->and(UserNotification::where('type', 'ads.launch.expired')->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$w['content']->id, $w['buyerUser']->id])->sort()->values()->all());
});

it('warns once on day 6 (24 h before), including the managers', function () {
    $w = LaunchWorld::make();
    LaunchWorld::launch($w, LaunchState::AwaitingApproval, ['expires_at' => now()->addHours(20)]);

    $this->artisan('ads:launch-sweep')->expectsOutput('Warned 1, expired 0, reassigned 0.');
    $this->artisan('ads:launch-sweep')->expectsOutput('Warned 0, expired 0, reassigned 0.');
    expect(UserNotification::where('type', 'ads.launch.expiring')->count())->toBe(4); // content, buyer, manager, admin
});

it('E9 / E19: expires a held waiting launch, never a launching one', function () {
    $w = LaunchWorld::make();
    $held = LaunchWorld::launch($w, LaunchState::OnHold, ['hold_from_state' => LaunchState::AwaitingApproval, 'expires_at' => now()->subMinute()]);
    $launching = LaunchWorld::launch($w, LaunchState::Launching, ['expires_at' => now()->subMinute(), 'captions' => [LaunchWorld::caption(8)]]);

    $this->artisan('ads:launch-sweep')->assertSuccessful();

    expect($held->fresh()->state)->toBe(LaunchState::Expired)->and($launching->fresh()->state)->toBe(LaunchState::Launching);
});

it('E12: re-points a launch under review to the buyer who holds the account today', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::BuyerReview);
    $newUser = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $new = MediaBuyer::factory()->create(['user_id' => $newUser->id, 'is_active' => true]);
    AdAccountAssignment::query()->update(['ends_on' => now('Africa/Cairo')->subDay()->toDateString()]);
    AdAccountAssignment::factory()->create(['ad_account_id' => $w['account']->id, 'media_buyer_id' => $new->id, 'starts_on' => now('Africa/Cairo')->toDateString(), 'ends_on' => null]);

    $this->artisan('ads:launch-sweep')->expectsOutput('Warned 0, expired 0, reassigned 1.');
    expect($l->fresh()->reviewer_buyer_id)->toBe($new->id)
        ->and(UserNotification::where('type', 'ads.launch.submitted')->where('user_id', $newUser->id)->count())->toBe(1);
});

it('is scheduled hourly without overlapping, and the setting is editable by supervisors', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'ads:launch-sweep'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('5 * * * *')->and($event->withoutOverlapping)->toBeTrue();

    $w = LaunchWorld::make();
    $this->actingAs($w['supervisor'])->put('/ads/setup/settings', ['launch_expiry_days' => 5])->assertSessionHasNoErrors();
    expect(app(LaunchSettings::class)->expiryDays())->toBe(5);
    $this->actingAs($w['supervisor'])->put('/ads/setup/settings', ['launch_expiry_days' => 90])->assertSessionHasErrors('launch_expiry_days');
});
