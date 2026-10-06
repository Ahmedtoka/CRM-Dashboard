<?php

use App\Ads\Launch\LaunchState;
use App\Models\Ad;
use App\Models\AdPublication;
use App\Models\AdsAuditLog;
use App\Models\AdWriteAction;
use Tests\Support\LaunchWorld;

beforeEach(fn () => LaunchWorld::boot());

function agPropose($test, $user, $account, string $ext, string $to)
{
    return $test->actingAs($user)->postJson('/ads/write-actions', [
        'type' => 'set_status', 'account_id' => $account->id, 'target' => ['level' => 'ad', 'external_id' => $ext], 'params' => ['to' => $to], 'reason' => 'test',
    ], ['Idempotency-Key' => 'k-'.bin2hex(random_bytes(8))]);
}

it('TC-08: refuses a Run of an unapproved launch ad for the buyer and for Ads authority, audited', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::AwaitingApproval);
    $ext = $l->publications()->value('external_ad_id');

    agPropose($this, $w['buyerUser'], $w['account'], $ext, 'active')->assertForbidden()->assertJsonPath('code', 'approval_required')
        ->assertJsonPath('details.launch_id', $l->public_id);
    agPropose($this, $w['admin'], $w['account'], $ext, 'active')->assertForbidden()->assertJsonPath('code', 'approval_required');

    expect(AdWriteAction::count())->toBe(0)
        ->and(AdsAuditLog::where('action', 'write.refused')->get()->pluck('meta.code')->unique()->all())->toBe(['approval_required']);
});

it('lets a Stop through, and Runs of ads made outside the CRM (E18)', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::AwaitingApproval);
    $outside = Ad::factory()->for($w['account'], 'account')->create(['status' => 'PAUSED']);

    agPropose($this, $w['buyerUser'], $w['account'], $l->publications()->value('external_ad_id'), 'paused')->assertSuccessful();
    agPropose($this, $w['buyerUser'], $w['account'], $outside->external_id, 'active')->assertSuccessful();
});

it('T14: lets the buyer Run again an ad of a launch that was approved once', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::Stopped);
    Ad::query()->whereIn('external_id', $l->publications()->pluck('external_ad_id'))->update(['status' => 'PAUSED', 'effective_status' => 'PAUSED']);

    agPropose($this, $w['buyerUser'], $w['account'], $l->publications()->value('external_ad_id'), 'active')->assertSuccessful();
});

it('refuses at confirm when the ad joined an unapproved launch after the proposal', function () {
    $w = LaunchWorld::make();
    $ad = Ad::factory()->for($w['account'], 'account')->create(['status' => 'PAUSED']);
    $res = agPropose($this, $w['buyerUser'], $w['account'], $ad->external_id, 'active')->assertSuccessful();
    $x = AdWriteAction::where('public_id', $res->json('action.id'))->sole();
    $l = LaunchWorld::launch($w, LaunchState::AwaitingApproval);
    AdPublication::where('ad_launch_id', $l->id)->first()->update(['external_ad_id' => $ad->external_id]);

    $this->actingAs($w['buyerUser'])->postJson("/ads/write-actions/{$x->public_id}/confirm", ['diff_hash' => $x->diff_hash])
        ->assertForbidden()->assertJsonPath('code', 'approval_required');
    expect($x->fresh()->state)->toBe('failed');
});
