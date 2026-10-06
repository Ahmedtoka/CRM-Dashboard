<?php

use App\Ads\AdsSettings;
use App\Ads\Launch\LaunchMonitor;
use App\Ads\Launch\LaunchState;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Models\Ad;
use App\Models\AdWriteAction;
use App\Models\UserNotification;
use Tests\Support\LaunchWorld;

beforeEach(fn () => LaunchWorld::boot());

function llAds($l)
{
    return Ad::query()->whereIn('external_id', $l->publications()->pluck('external_ad_id'));
}

it('follows the ads after a sync: all paused → stopped (T13), one active again → live (T14)', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::Live);

    llAds($l)->update(['status' => 'PAUSED']);
    app(LaunchMonitor::class)->afterSync($w['account']);
    expect($l->fresh()->state)->toBe(LaunchState::Stopped)->and($l->fresh()->stopped_at)->not->toBeNull();

    llAds($l)->update(['status' => 'ACTIVE']);
    app(LaunchMonitor::class)->afterSync($w['account']);
    expect($l->fresh()->state)->toBe(LaunchState::Live)->and($l->fresh()->stopped_at)->toBeNull();
});

it('E8: a launching launch whose Run outcome was unknown goes live when the sync sees the ad running', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::Launching, ['approved_at' => now(), 'last_error' => 'outcome_unknown']);
    llAds($l)->update(['status' => 'ACTIVE']);

    app(LaunchMonitor::class)->afterSync($w['account']);

    expect($l->fresh()->state)->toBe(LaunchState::Live)->and(UserNotification::where('type', 'ads.launch.live')->count())->toBe(2);
});

it('stops every running ad of a launch in one click through the pipeline, even with writes off', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::Live, ['captions' => [LaunchWorld::caption(1), LaunchWorld::caption(2)]]);
    app(AdsSettings::class)->set('writes_enabled', false);

    $this->actingAs($w['buyerUser'])->postJson("/ads/launches/{$l->public_id}/stop", [], ['Idempotency-Key' => 'stop-00000001'])->assertOk()
        ->assertJsonPath('ads.0.outcome', 'succeeded')->assertJsonPath('ads.1.outcome', 'succeeded')->assertJsonPath('launch.state', 'stopped');

    expect(AdWriteAction::pluck('source')->unique()->all())->toBe(['launch_stop'])->and(llAds($l)->pluck('status')->unique()->all())->toBe(['PAUSED']);
});

it('refuses a Stop from content', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::Live);

    $this->actingAs($w['content'])->postJson("/ads/launches/{$l->public_id}/stop", [], ['Idempotency-Key' => 'stop-00000002'])->assertForbidden()->assertJsonPath('code', 'out_of_scope');
});

it('T15: retiring stops the ads first; a failed Stop keeps it live (E15)', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::Live);
    FakeAdsDriver::failNext('setStatus', 'rejected');

    $this->actingAs($w['buyerUser'])->postJson("/ads/launches/{$l->public_id}/retire", ['reason' => 'خلص الموسم'], ['Idempotency-Key' => 'ret-00000001'])
        ->assertStatus(422)->assertJsonPath('code', 'retire_stop_failed');
    expect($l->fresh()->state)->toBe(LaunchState::Live);

    $this->actingAs($w['buyerUser'])->postJson("/ads/launches/{$l->public_id}/retire", ['reason' => 'خلص الموسم'], ['Idempotency-Key' => 'ret-00000002'])
        ->assertOk()->assertJsonPath('launch.state', 'retired');
    expect($l->fresh()->retired_at)->not->toBeNull()->and($l->fresh()->decision_reason)->toBe('خلص الموسم');
});
