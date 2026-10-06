<?php

use App\Ads\AdsSettings;
use App\Ads\Control\Write\WriteDenied;
use App\Ads\Launch\ApproveLaunch;
use App\Ads\Launch\LaunchChecks;
use App\Ads\Launch\LaunchState;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Ads\Sync\SyncAdAccount;
use App\Models\Ad;
use App\Models\AdLaunch;
use App\Models\AdPublication;
use App\Models\AdsAuditLog;
use App\Models\AdWriteAction;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeLandingProbe;
use Tests\Support\LaunchWorld;

beforeEach(fn () => LaunchWorld::boot());

/** An awaiting launch with $captions ads, and what its card shows the approver. */
function apCard(array $w, int $captions = 1, ?User $viewer = null): array
{
    $l = LaunchWorld::launch($w, LaunchState::AwaitingApproval, ['captions' => array_map(fn ($i) => LaunchWorld::caption($i), range(1, $captions))]);
    $results = app(LaunchChecks::class)->run($l, 'approve', $viewer ?? $w['manager']);

    return [$l, LaunchChecks::hash($l, $results)];
}

function apApprove($test, User $u, AdLaunch $l, string $hash, array $over = [])
{
    return $test->actingAs($u)->withSession(LaunchWorld::confirmed())
        ->postJson("/ads/approvals/{$l->public_id}/approve", $over + ['revision' => $l->revision, 'checks_hash' => $hash, 'ack_warnings' => []]);
}

function apStatuses(): array
{
    return Cache::get('ads-fake-writer')['statuses'] ?? [];
}

it('TC-01: the manager approves and the ads go live through the write pipeline, ad level only', function () {
    $w = LaunchWorld::make();
    [$l, $hash] = apCard($w, 2);

    apApprove($this, $w['manager'], $l, $hash)->assertOk()->assertJsonPath('launch.state', 'live')
        ->assertJsonPath('ads.0.outcome', 'succeeded')->assertJsonPath('ads.1.outcome', 'succeeded')->assertJsonPath('self_approved', false);

    $l->refresh();
    $actions = AdWriteAction::orderBy('id')->get();
    expect($l->state)->toBe(LaunchState::Live)->and($l->approved_at)->not->toBeNull()->and($l->decided_by_id)->toBe($w['manager']->id)
        ->and(collect(apStatuses())->pluck('level')->unique()->all())->toBe(['ad'])
        ->and(collect(apStatuses())->pluck('status')->unique()->all())->toBe(['active'])
        ->and($actions)->toHaveCount(2)->and($actions->pluck('source')->unique()->all())->toBe(['launch_approval'])
        ->and($actions->pluck('source_ref')->unique()->all())->toBe([$l->public_id])
        ->and($actions->pluck('confirmed_by_id')->unique()->all())->toBe([$w['manager']->id])
        ->and(AdPublication::where('ad_launch_id', $l->id)->whereNotNull('run_write_action_id')->count())->toBe(2)
        ->and(Ad::whereIn('external_id', $l->publications()->pluck('external_ad_id'))->pluck('status')->unique()->all())->toBe(['ACTIVE'])
        ->and(AdsAuditLog::where('subject_type', 'AdLaunch')->where('subject_id', $l->id)->pluck('action')->all())->toContain('launch.launching', 'launch.live')
        ->and(UserNotification::where('type', 'ads.launch.live')->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$w['content']->id, $w['buyerUser']->id])->sort()->values()->all());
});

it('G3: answers 423 without a recent password, and the in-page re-auth unlocks it', function () {
    $w = LaunchWorld::make();
    [$l, $hash] = apCard($w);

    $body = ['revision' => $l->revision, 'checks_hash' => $hash, 'ack_warnings' => []];

    $this->actingAs($w['manager'])->postJson("/ads/approvals/{$l->public_id}/approve", $body)->assertStatus(423);
    $this->actingAs($w['manager'])->withSession(['auth.password_confirmed_at' => time() - 901])->postJson("/ads/approvals/{$l->public_id}/approve", $body)->assertStatus(423);
    $this->actingAs($w['manager'])->postJson('/ads/reauth', ['password' => 'wrong'])->assertStatus(422)->assertJsonValidationErrors('password');
    $this->actingAs($w['manager'])->postJson('/ads/reauth', ['password' => 'password'])->assertOk()->assertJsonStructure(['valid_until'])
        ->assertSessionHas('auth.password_confirmed_at');
    $this->actingAs($w['manager'])->withSession(['auth.password_confirmed_at' => time() - 60])->postJson("/ads/approvals/{$l->public_id}/approve", $body)->assertOk();
    expect($l->fresh()->state)->toBe(LaunchState::Live);
});

it('refuses buyers, content and supervisors without Ads authority', function () {
    $w = LaunchWorld::make();
    [$l, $hash] = apCard($w);

    apApprove($this, $w['buyerUser'], $l, $hash)->assertForbidden()->assertJsonPath('code', 'ads_authority_required');
    apApprove($this, $w['supervisor'], $l, $hash)->assertForbidden();
    apApprove($this, $w['content'], $l, $hash)->assertForbidden();
});

it('TC-09 / O1: a non-admin who prepared or forwarded cannot approve; an admin may, logged self_approved', function () {
    $w = LaunchWorld::make();
    [$l, $hash] = apCard($w);
    $l->forceFill(['forwarded_by_id' => $w['manager']->id])->save();

    apApprove($this, $w['manager'], $l, $hash)->assertForbidden()->assertJsonPath('code', 'self_approval');

    $l->forceFill(['prepared_by_id' => $w['admin']->id])->save();
    $adminHash = LaunchChecks::hash($l->fresh(), app(LaunchChecks::class)->run($l->fresh(), 'approve', $w['admin']));
    apApprove($this, $w['admin'], $l->fresh(), $adminHash)->assertOk()->assertJsonPath('self_approved', true);

    expect($l->fresh()->self_approved)->toBeTrue()
        ->and(AdsAuditLog::where('action', 'launch.launching')->sole()->meta['self_approved'])->toBeTrue();
});

it('TC-10 / G6: 409 on a stale revision or a changed price; a landing flap only needs an acknowledgement', function () {
    $w = LaunchWorld::make();
    [$l, $hash] = apCard($w);

    apApprove($this, $w['manager'], $l, $hash, ['revision' => $l->revision + 1])->assertStatus(409)->assertJsonPath('code', 'launch_changed');

    ProductVariant::query()->update(['price' => 455]);
    apApprove($this, $w['manager'], $l, $hash)->assertStatus(409)->assertJsonPath('code', 'launch_changed');
    ProductVariant::query()->update(['price' => 450]);

    FakeLandingProbe::$status = 503;
    apApprove($this, $w['manager'], $l, $hash)->assertStatus(422)->assertJsonPath('code', 'warnings_unacknowledged')->assertJsonPath('details.keys.0', 'landing_http');
    apApprove($this, $w['manager'], $l, $hash, ['ack_warnings' => ['landing_http']])->assertOk();
});

it('TC-11 / E5: a second approval loses with the winner name', function () {
    $w = LaunchWorld::make();
    [$l, $hash] = apCard($w);
    $caught = null;
    // The race: a second approval arrives while the first one's Run is on its way to the platform.
    FakeAdsDriver::beforeSetStatus(function () use ($w, $l, $hash, &$caught) {
        try {
            app(ApproveLaunch::class)->approve($w['admin'], AdLaunch::findOrFail($l->id), $l->revision, $hash, []);
        } catch (WriteDenied $e) {
            $caught = $e;
        }
    });

    apApprove($this, $w['manager'], $l, $hash)->assertOk();

    expect($caught?->errorCode)->toBe('launch_taken')->and($caught->status)->toBe(409)->and($caught->details['by'])->toBe('Mona')
        ->and(AdWriteAction::count())->toBe(1);
});

it('TC-14 / E7: a partial Run failure goes live with the failure listed; all failed goes back to awaiting, still gated', function () {
    $w = LaunchWorld::make();
    [$l, $hash] = apCard($w, 2);
    FakeAdsDriver::failNext('setStatus', 'rejected');

    apApprove($this, $w['manager'], $l, $hash)->assertOk()->assertJsonPath('launch.state', 'live')
        ->assertJsonPath('ads.0.outcome', 'failed')->assertJsonPath('ads.1.outcome', 'succeeded');
    expect($l->fresh()->last_error)->not->toBeNull()
        ->and(UserNotification::where('type', 'ads.launch.live')->first()->data['failed'])->toBe(1);

    [$m, $mHash] = apCard($w, 1);
    FakeAdsDriver::failNext('setStatus', 'rejected');
    apApprove($this, $w['manager'], $m, $mHash)->assertOk()->assertJsonPath('launch.state', 'awaiting_approval');
    expect($m->fresh()->approved_at)->toBeNull()->and($m->fresh()->last_error)->not->toBeNull();

    $this->actingAs($w['buyerUser'])->postJson('/ads/write-actions', [
        'type' => 'set_status', 'account_id' => $w['account']->id, 'target' => ['level' => 'ad', 'external_id' => $m->publications()->value('external_ad_id')],
        'params' => ['to' => 'active'], 'reason' => 'x',
    ], ['Idempotency-Key' => 'k-gate-0001'])->assertForbidden()->assertJsonPath('code', 'approval_required');
});

it('TC-15 / G7: the kill switch disables approve; Stop still works', function () {
    $w = LaunchWorld::make();
    [$l, $hash] = apCard($w);
    $live = LaunchWorld::launch($w, LaunchState::Live, ['captions' => [LaunchWorld::caption(7)]]);
    app(AdsSettings::class)->set('writes_enabled', false);

    apApprove($this, $w['manager'], $l, $hash)->assertStatus(422)->assertJsonPath('code', 'checks_failed')->assertJsonPath('details.keys.0', 'ap.writes_on');
    expect($l->fresh()->state)->toBe(LaunchState::AwaitingApproval);

    $res = $this->actingAs($w['buyerUser'])->postJson('/ads/write-actions', [
        'type' => 'set_status', 'account_id' => $w['account']->id, 'target' => ['level' => 'ad', 'external_id' => $live->publications()->value('external_ad_id')],
        'params' => ['to' => 'paused'], 'reason' => 'stop',
    ], ['Idempotency-Key' => 'k-stop-0001'])->assertSuccessful();
    $x = AdWriteAction::where('public_id', $res->json('action.id'))->sole();
    $this->actingAs($w['buyerUser'])->postJson("/ads/write-actions/{$x->public_id}/confirm", ['diff_hash' => $x->diff_hash])->assertOk();
});

it('blocks over the budget cap and asks to acknowledge a paused parent (D4: the ad only, never the parent)', function () {
    $w = LaunchWorld::make();
    [$l, $hash] = apCard($w);
    $ext = $l->publications()->value('external_ad_id');
    $parents = fn (string $status, int $budget) => ['parents' => [
        ['level' => 'adset', 'status' => $status, 'dailyBudgetMinor' => $budget, 'lifetimeBudgetMinor' => null, 'endsAt' => null],
        ['level' => 'campaign', 'status' => 'ACTIVE', 'dailyBudgetMinor' => null, 'lifetimeBudgetMinor' => null, 'endsAt' => null],
    ]];

    app(FakeAdsDriver::class)->seedObject('ad', $ext, $parents('ACTIVE', 5_000_000));
    apApprove($this, $w['manager'], $l, $hash)->assertStatus(422)->assertJsonPath('details.keys.0', 'ap.budget_cap');

    app(FakeAdsDriver::class)->seedObject('ad', $ext, $parents('PAUSED', 50_000));
    apApprove($this, $w['manager'], $l, $hash)->assertStatus(422)->assertJsonPath('code', 'warnings_unacknowledged');
    apApprove($this, $w['manager'], $l, $hash, ['ack_warnings' => ['parent_paused']])->assertOk();
    expect(collect(apStatuses())->pluck('level')->all())->toBe(['ad']);
});

it('TC-04 / T9: the manager returns to the buyer with a reason; paused ads archived; a new forward is not a duplicate', function () {
    Queue::fake([SyncAdAccount::class]);
    $w = LaunchWorld::make();
    [$l] = apCard($w);

    $this->actingAs($w['manager'])->postJson("/ads/approvals/{$l->public_id}/return", ['code' => 'price_wrong', 'text' => 'السعر'])->assertOk()
        ->assertJsonPath('launch.state', 'buyer_review');

    $l->refresh();
    expect($l->expires_at)->toBeNull()->and(AdPublication::where('ad_launch_id', $l->id)->whereNull('archived_at')->count())->toBe(0)
        ->and(UserNotification::where('type', 'ads.launch.returned')->where('user_id', $w['buyerUser']->id)->first()->data['code'])->toBe('price_wrong');
    $this->actingAs($w['buyerUser'])->postJson("/ads/launches/{$l->public_id}/forward", ['revision' => $l->revision])->assertOk();
});

it('TC-05 / T10: reject is terminal, archives, notifies; buyers cannot return or reject', function () {
    $w = LaunchWorld::make();
    [$l] = apCard($w);

    $this->actingAs($w['buyerUser'])->postJson("/ads/approvals/{$l->public_id}/reject", ['code' => 'off_brand'])->assertForbidden();
    $this->actingAs($w['manager'])->postJson("/ads/approvals/{$l->public_id}/reject", ['code' => 'off_brand'])->assertOk()->assertJsonPath('launch.state', 'rejected');
    expect(AdPublication::where('ad_launch_id', $l->id)->whereNotNull('archived_at')->count())->toBe(1)
        ->and(UserNotification::where('type', 'ads.launch.rejected')->count())->toBe(2);
    $this->actingAs($w['manager'])->postJson("/ads/approvals/{$l->public_id}/return", ['code' => 'other', 'text' => 'x'])->assertStatus(409);
});
