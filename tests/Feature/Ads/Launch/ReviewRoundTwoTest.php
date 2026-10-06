<?php

use App\Ads\Launch\HttpLandingProbe;
use App\Ads\Launch\LaunchMonitor;
use App\Ads\Launch\LaunchService;
use App\Ads\Launch\LaunchState;
use App\Ads\Materials\StockWatcher;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdLaunch;
use App\Models\AdMaterial;
use App\Models\AdPublication;
use App\Models\AdsAuditLog;
use App\Models\AdWriteAction;
use App\Models\MediaBuyer;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeLandingProbe;
use Tests\Support\LaunchWorld;

beforeEach(function () {
    LaunchWorld::boot();
    $this->withoutVite();
});

function r2Launching(array $w, array $attrs = []): AdLaunch
{
    $l = LaunchWorld::launch($w, LaunchState::AwaitingApproval, $attrs);
    $l->forceFill(['state' => LaunchState::Launching, 'approved_at' => now()->subMinutes(20), 'decided_by_id' => $w['manager']->id, 'decided_at' => now()->subMinutes(20)])->save();

    return $l->fresh();
}

it('hands a stuck launching launch back to awaiting approval after 15 minutes and tells the approver', function () {
    $w = LaunchWorld::make();
    $l = r2Launching($w);

    $this->artisan('ads:launch-sweep')->expectsOutput('Unstuck 1.')->assertSuccessful();

    $l->refresh();
    expect($l->state)->toBe(LaunchState::AwaitingApproval)->and($l->approved_at)->toBeNull()
        ->and(AdsAuditLog::where('action', 'launch.approve_failed')->count())->toBe(1)
        ->and(UserNotification::where('type', 'ads.launch.approve_failed')->where('user_id', $w['manager']->id)->count())->toBe(1);
});

it('leaves a launching launch alone while it is young, a Run is open or an ad already runs', function () {
    $w = LaunchWorld::make();
    $young = r2Launching($w);
    $young->forceFill(['approved_at' => now()->subMinutes(5)])->save();
    $open = r2Launching($w, ['captions' => [LaunchWorld::caption(2)]]);
    AdWriteAction::factory()->create(['source' => 'launch_approval', 'source_ref' => $open->public_id, 'state' => AdWriteAction::UNKNOWN]);
    $running = r2Launching($w, ['captions' => [LaunchWorld::caption(3)]]);
    Ad::query()->whereIn('external_id', $running->publications()->pluck('external_ad_id'))->update(['status' => 'ACTIVE']);

    app(LaunchService::class)->sweep();

    expect($young->fresh()->state)->toBe(LaunchState::Launching)->and($open->fresh()->state)->toBe(LaunchState::Launching)
        ->and($running->fresh()->state)->toBe(LaunchState::Launching);
});

it('lets the monitor hand back a stuck launching launch after a sync', function () {
    $w = LaunchWorld::make();
    $l = r2Launching($w);

    app(LaunchMonitor::class)->afterSync($w['account']);

    expect($l->fresh()->state)->toBe(LaunchState::AwaitingApproval);
});

it('serves the approvals page and the bulk plan without a live landing request; the single-launch checks probe live', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::AwaitingApproval);

    $this->actingAs($w['manager'])->get('/ads/approvals')->assertOk();
    $this->actingAs($w['manager'])->withSession(LaunchWorld::confirmed())->postJson('/ads/approvals/bulk')->assertOk();
    expect(FakeLandingProbe::$live)->toBe([]);

    $this->actingAs($w['manager'])->getJson("/ads/launches/{$l->public_id}/checks")->assertOk();
    expect(FakeLandingProbe::$live)->toHaveCount(1);
});

it('caches a failed landing probe too, and never requests in cached-only mode', function () {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;

        throw new ConnectionException('down');
    });
    $probe = new HttpLandingProbe;

    expect($probe->status('https://lv.test/a', false))->toBeNull()->and($probe->known('https://lv.test/a'))->toBeFalse();
    expect($calls)->toBe(0);
    expect($probe->status('https://lv.test/a'))->toBeNull()->and($probe->status('https://lv.test/a'))->toBeNull()
        ->and($probe->known('https://lv.test/a'))->toBeTrue();
    expect($calls)->toBe(1);
});

it('archives a held launch when the material is retired', function () {
    $w = LaunchWorld::make();
    $held = LaunchWorld::launch($w, LaunchState::AwaitingApproval);
    $held->forceFill(['state' => LaunchState::OnHold, 'hold_from_state' => LaunchState::AwaitingApproval])->save();

    $this->actingAs($w['buyerUser'])->post("/ads/materials/{$w['material']->id}/retire", [], ['Idempotency-Key' => 'r2-ret-0001'])->assertSessionHasNoErrors();

    expect($held->fresh()->state)->toBe(LaunchState::Expired)
        ->and(AdPublication::where('ad_launch_id', $held->id)->whereNull('archived_at')->count())->toBe(0);
});

it('retires nothing when one running launch of the material is not the user one to retire', function () {
    $w = LaunchWorld::make();
    $mine = LaunchWorld::launch($w, LaunchState::Live);
    $foreign = LaunchWorld::launch($w, LaunchState::Live, ['captions' => [LaunchWorld::caption(4)], 'reviewer_buyer_id' => MediaBuyer::factory()->create()->id]);
    $foreign->forceFill(['ad_account_id' => AdAccount::factory()->meta()->create()->id])->save();

    $this->actingAs($w['buyerUser'])->post("/ads/materials/{$w['material']->id}/retire", [], ['Idempotency-Key' => 'r2-ret-0002'])->assertSessionHasErrors('material');

    expect($mine->fresh()->state)->toBe(LaunchState::Live)->and($foreign->fresh()->state)->toBe(LaunchState::Live)
        ->and(AdWriteAction::count())->toBe(0);
});

it('re-points a create_failed launch to the buyer holding the account today', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::CreateFailed);
    $newUser = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $new = MediaBuyer::factory()->create(['user_id' => $newUser->id, 'is_active' => true]);
    AdAccountAssignment::query()->update(['ends_on' => now('Africa/Cairo')->subDay()->toDateString()]);
    AdAccountAssignment::factory()->create(['ad_account_id' => $w['account']->id, 'media_buyer_id' => $new->id, 'starts_on' => now('Africa/Cairo')->toDateString(), 'ends_on' => null]);

    app(LaunchService::class)->sweep();

    expect($l->fresh()->reviewer_buyer_id)->toBe($new->id);
});

it('ends a held launch whose product is gone instead of holding it forever', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::Draft);
    $l->forceFill(['state' => LaunchState::OnHold, 'hold_from_state' => LaunchState::Draft])->save();
    AdMaterial::whereKey($w['material']->id)->update(['product_id' => null]);

    expect(app(StockWatcher::class)->holds()['released'])->toBe(1)
        ->and($l->fresh()->state)->toBe(LaunchState::Expired)->and($l->fresh()->decision_code)->toBe('product_gone');
});
