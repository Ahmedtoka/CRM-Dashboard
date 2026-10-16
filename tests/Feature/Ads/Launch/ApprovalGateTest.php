<?php

use App\Ads\Control\PublicationLinker;
use App\Ads\Launch\LaunchChecks;
use App\Ads\Launch\LaunchService;
use App\Ads\Launch\LaunchState;
use App\Ads\Sync\SyncAdAccount;
use App\Models\Ad;
use App\Models\AdPublication;
use App\Models\AdsAuditLog;
use App\Models\AdWriteAction;
use Illuminate\Support\Facades\Queue;
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

it('review r1: refuses a Run of an archived round-1 ad after the launch was returned, re-forwarded and approved', function () {
    Queue::fake([SyncAdAccount::class]);
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::AwaitingApproval);
    $old = $l->publications()->value('external_ad_id');

    $this->actingAs($w['manager'])->postJson("/ads/approvals/{$l->public_id}/return", ['code' => 'price_wrong'])->assertOk();
    $this->actingAs($w['buyerUser'])->postJson("/ads/launches/{$l->public_id}/forward", ['revision' => $l->fresh()->revision])->assertOk();
    foreach (AdPublication::where('ad_launch_id', $l->id)->whereNull('archived_at')->get() as $p) {
        Ad::factory()->for($w['account'], 'account')->create(['external_id' => $p->external_ad_id, 'ad_set_id' => $w['adset']->id, 'ad_campaign_id' => $w['campaign']->id, 'status' => 'PAUSED', 'effective_status' => 'PAUSED']);
    }
    app(PublicationLinker::class)->link($w['account']);
    $l->refresh();
    expect($l->state)->toBe(LaunchState::AwaitingApproval);
    $hash = LaunchChecks::hash($l, app(LaunchChecks::class)->run($l, 'approve', $w['manager']));
    $this->actingAs($w['manager'])->withSession(LaunchWorld::confirmed())
        ->postJson("/ads/approvals/{$l->public_id}/approve", ['revision' => $l->revision, 'checks_hash' => $hash, 'ack_warnings' => []])
        ->assertOk()->assertJsonPath('launch.state', 'live');

    agPropose($this, $w['admin'], $w['account'], $old, 'active')->assertForbidden()->assertJsonPath('code', 'approval_required');
});

it('final review A1: refuses a Run of a retired launch ad through write-actions and the legacy endpoint; Stop still allowed', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::Live);
    $this->actingAs($w['manager'])->withSession(LaunchWorld::confirmed());
    app(LaunchService::class)->retire($w['manager'], $l->fresh(), 'done', 'k-retire');
    expect($l->fresh()->state)->toBe(LaunchState::Retired)->and($l->fresh()->approved_at)->not->toBeNull();
    $ext = $l->publications()->value('external_ad_id');
    Ad::query()->where('external_id', $ext)->update(['status' => 'PAUSED', 'effective_status' => 'PAUSED']);

    agPropose($this, $w['buyerUser'], $w['account'], $ext, 'active')->assertForbidden()
        ->assertJsonPath('code', 'approval_required')->assertJsonPath('details.launch_state', 'retired');
    $this->actingAs($w['buyerUser'])->withSession(LaunchWorld::confirmed())->postJson('/ads/actions/status', [
        'account_id' => $w['account']->id, 'level' => 'ad', 'external_id' => $ext, 'status' => 'active',
    ])->assertForbidden()->assertJsonPath('code', 'approval_required');

    agPropose($this, $w['buyerUser'], $w['account'], $ext, 'paused')->assertSuccessful();
});

/** A6: the create request timed out, so the publication is ERROR without the ad id, but Meta made the ad and the sync has it. */
function agOrphan(array $w, $l): array
{
    $p = AdPublication::where('ad_launch_id', $l->id)->orderBy('id')->first();
    $ext = $p->external_ad_id;
    AdPublication::whereKey($p->id)->update(['status' => AdPublication::ERROR, 'external_ad_id' => null, 'linked_at' => null,
        'ad_requested_at' => now()->subMinutes(5), 'error' => 'timeout '.__('ads.publish.create_may_exist')]);
    $w['material']->ads()->detach(Ad::where('external_id', $ext)->value('id'));

    return [$p->fresh(), $ext];
}

it('A6: refuses a Run of an unapproved launch ad whose id never came back (matched by ad name and ad set)', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::AwaitingApproval);
    [, $ext] = agOrphan($w, $l);

    agPropose($this, $w['buyerUser'], $w['account'], $ext, 'active')->assertForbidden()
        ->assertJsonPath('code', 'approval_required')->assertJsonPath('details.launch_id', $l->public_id);
    expect(AdWriteAction::count())->toBe(0);
});

it('A6: an ad of the same name in another ad set is not the orphan and runs', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::AwaitingApproval);
    [$p] = agOrphan($w, $l);
    $otherSet = \App\Models\AdSet::factory()->create(['ad_campaign_id' => $w['campaign']->id, 'status' => 'ACTIVE']);
    $other = Ad::factory()->for($w['account'], 'account')->create(['name' => $p->ad_name, 'ad_set_id' => $otherSet->id,
        'ad_campaign_id' => $w['campaign']->id, 'status' => 'PAUSED', 'effective_status' => 'PAUSED']);

    agPropose($this, $w['buyerUser'], $w['account'], $other->external_id, 'active')->assertSuccessful();
});

it('A6: the linker adopts the orphaned ad into its errored publication, so the launch gate holds on the id from then on', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::AwaitingApproval);
    [$p, $ext] = agOrphan($w, $l);

    expect(app(PublicationLinker::class)->link($w['account']))->toBe(1);

    $p->refresh();
    expect($p->external_ad_id)->toBe($ext)->and($p->status)->toBe(AdPublication::DONE)->and($p->error)->toBeNull()->and($p->linked_at)->not->toBeNull()
        ->and($w['material']->ads()->where('external_id', $ext)->exists())->toBeTrue();
    agPropose($this, $w['buyerUser'], $w['account'], $ext, 'active')->assertForbidden()->assertJsonPath('code', 'approval_required');
});

it('A6: the linker never adopts an ad another publication already owns, nor one in another ad set', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::AwaitingApproval);
    [$p, $ext] = agOrphan($w, $l);
    $otherSet = \App\Models\AdSet::factory()->create(['ad_campaign_id' => $w['campaign']->id, 'status' => 'ACTIVE']);
    Ad::where('external_id', $ext)->update(['ad_set_id' => $otherSet->id]);
    $owned = Ad::factory()->for($w['account'], 'account')->create(['name' => $p->ad_name, 'ad_set_id' => $w['adset']->id, 'ad_campaign_id' => $w['campaign']->id]);
    $p->replicate()->forceFill(['ad_launch_id' => null, 'open_key' => null, 'status' => AdPublication::DONE,
        'external_ad_id' => $owned->external_id, 'linked_at' => now(), 'error' => null])->save();

    expect(app(PublicationLinker::class)->link($w['account']))->toBe(0)
        ->and($p->fresh()->external_ad_id)->toBeNull()->and($p->fresh()->status)->toBe(AdPublication::ERROR);
});
