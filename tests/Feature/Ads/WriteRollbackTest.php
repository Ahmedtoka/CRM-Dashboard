<?php

use App\Ads\AdsSettings;
use App\Ads\Control\Write\WriteActionService;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdCampaign;
use App\Models\AdsAuditLog;
use App\Models\AdWriteAction;
use App\Models\MediaBuyer;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::forget('ads-fake-writer');
    FakeAdsDriver::reset();
    config(['crm.ads.drivers.meta' => 'fake', 'crm.ads.drivers.tiktok' => 'fake', 'crm.ads.drivers.google' => 'fake', 'crm.ads.write_sandbox_accounts' => []]);
});

function rbBuyer(AdAccount $acc): User
{
    $user = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $buyer = MediaBuyer::factory()->create(['user_id' => $user->id]);
    AdAccountAssignment::factory()->create(['ad_account_id' => $acc->id, 'media_buyer_id' => $buyer->id, 'starts_on' => '2026-01-01', 'ends_on' => null]);

    return $user;
}

/** Proposes and confirms through the service; returns the finished action. */
function rbDone(User $u, AdAccount $acc, string $level, string $id, string $to): AdWriteAction
{
    $svc = app(WriteActionService::class);
    $x = $svc->propose($u, $acc, $level, $id, $to, 'test', 'k-'.bin2hex(random_bytes(8)))['action'];

    return $svc->confirm($u, $x, $x->diff_hash);
}

function rbPost($test, User $u, AdWriteAction $done, string $key = 'rollback-0001')
{
    return $test->actingAs($u)->postJson("/ads/write-actions/{$done->public_id}/rollback", ['reason' => 'undo'], ['Idempotency-Key' => $key]);
}

it('proposes a Run to undo a succeeded Stop and marks the Stop rolled_back once the Run succeeds', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'ACTIVE']);
    $buyer = rbBuyer($acc);
    $stop = rbDone($buyer, $acc, 'ad', $ad->external_id, 'paused');

    $res = rbPost($this, $buyer, $stop)->assertCreated()->assertJsonPath('action.to', 'active')
        ->assertJsonPath('action.source', 'rollback')->assertJsonPath('action.rollback_of', $stop->public_id);
    $run = AdWriteAction::where('public_id', $res->json('action.id'))->sole();
    expect($run->state)->toBe('proposed')->and($run->rollback_of_id)->toBe($stop->id)->and($run->source_ref)->toBe($stop->public_id)
        ->and($stop->fresh()->state)->toBe('succeeded');

    rbPost($this, $buyer, $stop)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    $this->actingAs($buyer)->postJson("/ads/write-actions/{$run->public_id}/confirm", ['diff_hash' => $run->diff_hash])->assertOk();

    $stop->refresh();
    expect($stop->state)->toBe('rolled_back')->and($stop->rolled_back_by_id)->toBe($run->id)
        ->and(AdsAuditLog::where('action', 'write.rolled_back')->where('subject_id', $stop->id)->count())->toBe(1)
        ->and($ad->fresh()->status)->toBe('ACTIVE');
    rbPost($this, $buyer, $stop, 'rollback-0002')->assertStatus(422)->assertJsonPath('code', 'not_reversible');
});

it('follows the inverse rules with the kill switch off: undoing a Stop is a Run (503), undoing a Run is a Stop (allowed)', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $ad2 = Ad::factory()->for($acc, 'account')->create(['status' => 'ACTIVE']);
    $buyer = rbBuyer($acc);
    $run = rbDone($buyer, $acc, 'ad', $ad->external_id, 'active');
    $stop = rbDone($buyer, $acc, 'ad', $ad2->external_id, 'paused');
    app(AdsSettings::class)->set('writes_enabled', false);

    rbPost($this, $buyer, $stop, 'rollback-stop')->assertStatus(503)->assertJsonPath('code', 'writes_disabled');
    rbPost($this, $buyer, $run, 'rollback-run1')->assertCreated()->assertJsonPath('action.to', 'paused');
});

it('refuses to roll back a failed action', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $buyer = rbBuyer($acc);
    FakeAdsDriver::failNext('setStatus', 'rejected');
    $failed = rbDone($buyer, $acc, 'ad', $ad->external_id, 'paused');
    expect($failed->state)->toBe('failed');

    rbPost($this, $buyer, $failed)->assertStatus(422)->assertJsonPath('code', 'not_reversible');
    expect(AdWriteAction::count())->toBe(1);
});

it('a buyer cannot roll back a campaign-level action', function () {
    $acc = AdAccount::factory()->meta()->create();
    $camp = AdCampaign::factory()->for($acc, 'account')->create();
    $admin = User::factory()->adsAuthority()->create(['role' => UserRole::Admin]);
    $buyer = rbBuyer($acc);
    $stop = rbDone($admin, $acc, 'campaign', $camp->external_id, 'paused');
    // The undo is a Run: the Run guard needs a readable budget (a CBO campaign; the fake campaign has none by default).
    app(FakeAdsDriver::class)->seedObject('campaign', $camp->external_id, ['dailyBudgetMinor' => 100000]);

    rbPost($this, $buyer, $stop)->assertForbidden()->assertJsonPath('code', 'ads_authority_required');
    rbPost($this, $admin, $stop)->assertCreated();
});

it('hides the action from someone who cannot see it', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $buyer = rbBuyer($acc);
    $stop = rbDone($buyer, $acc, 'ad', $ad->external_id, 'paused');
    $other = rbBuyer(AdAccount::factory()->meta()->create());

    rbPost($this, $other, $stop)->assertNotFound();
});

it('needs an Idempotency-Key', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $buyer = rbBuyer($acc);
    $stop = rbDone($buyer, $acc, 'ad', $ad->external_id, 'paused');

    $this->actingAs($buyer)->postJson("/ads/write-actions/{$stop->public_id}/rollback")->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});
