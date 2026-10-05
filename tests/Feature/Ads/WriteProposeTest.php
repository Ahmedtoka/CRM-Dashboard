<?php

use App\Ads\AdsSettings;
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
    config(['crm.ads.drivers.meta' => 'fake', 'crm.ads.drivers.tiktok' => 'fake', 'crm.ads.drivers.google' => 'fake', 'crm.ads.write_sandbox_accounts' => []]);
});

function wpBuyer(AdAccount $acc): User
{
    $user = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $buyer = MediaBuyer::factory()->create(['user_id' => $user->id]);
    AdAccountAssignment::factory()->create(['ad_account_id' => $acc->id, 'media_buyer_id' => $buyer->id, 'starts_on' => '2026-01-01', 'ends_on' => null]);

    return $user;
}

function wpPropose($test, User $u, array $body, ?string $key = 'key-0001')
{
    $headers = $key === null ? [] : ['Idempotency-Key' => $key];

    return $test->actingAs($u)->postJson('/ads/write-actions', $body, $headers);
}

function wpBody(AdAccount $acc, string $level, string $id, string $to = 'paused', array $over = []): array
{
    return $over + ['type' => 'set_status', 'account_id' => $acc->id, 'target' => ['level' => $level, 'external_id' => $id], 'params' => ['to' => $to], 'reason' => 'ROAS low'];
}

it('records a buyer Stop proposal without calling the platform or taking a key', function () {
    $acc = AdAccount::factory()->meta()->create(['name' => 'LV Main']);
    $ad = Ad::factory()->for($acc, 'account')->create(['name' => 'Tired ad', 'status' => 'ACTIVE']);
    $buyer = wpBuyer($acc);

    $res = wpPropose($this, $buyer, wpBody($acc, 'ad', $ad->external_id))->assertCreated();

    $x = AdWriteAction::sole();
    expect($x->state)->toBe('proposed')->and($x->open_business_key)->toBeNull()->and($x->to_status)->toBe('paused')
        ->and($x->target_key)->toBe($acc->id.':ad:'.$ad->external_id)->and($x->proposed_by_id)->toBe($buyer->id)
        ->and($x->idempotency_key)->toBe('key-0001')->and($x->request_hash)->toHaveLength(64)
        ->and($x->reason)->toBe('ROAS low')->and($x->source)->toBe('ui')->and($x->target_name)->toBe('Tired ad')
        ->and($x->account_name)->toBe('LV Main')->and($x->from_status)->toBe('ACTIVE')
        ->and($x->expires_at->between(now()->addMinutes(9), now()->addMinutes(11)))->toBeTrue()
        ->and(Cache::get('ads-fake-writer')['statuses'] ?? [])->toBe([]);
    $res->assertJsonPath('action.id', $x->public_id)->assertJsonPath('action.state', 'proposed')
        ->assertJsonPath('action.level', 'ad')->assertJsonPath('action.to', 'paused')->assertJsonPath('action.name', 'Tired ad')
        ->assertJsonPath('action.account', 'LV Main')->assertJsonPath('diff.0.path', 'status')->assertJsonPath('diff.0.after', 'PAUSED')
        ->assertJsonPath('diff_hash', $x->diff_hash);
    $audit = AdsAuditLog::where('action', 'write.proposed')->sole();
    expect($audit->subject_type)->toBe('AdWriteAction')->and($audit->subject_id)->toBe($x->id)
        ->and($audit->meta['public_id'])->toBe($x->public_id)->and($audit->meta['diff_hash'])->toBe($x->diff_hash);
});

it('replays the same key and body, and refuses the same key with another body', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $buyer = wpBuyer($acc);
    $first = wpPropose($this, $buyer, wpBody($acc, 'ad', $ad->external_id))->assertCreated();

    wpPropose($this, $buyer, wpBody($acc, 'ad', $ad->external_id))->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('action.id', $first->json('action.id'));
    expect(AdWriteAction::count())->toBe(1);

    wpPropose($this, $buyer, wpBody($acc, 'ad', $ad->external_id, 'active'))->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_key_reused')->assertJsonPath('errors.status.0', __('ads.errors.idempotency_key_reused'));
    expect(AdWriteAction::count())->toBe(1);
});

it('lets two proposals with different keys coexist on the same ad (no lock at proposal)', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $buyer = wpBuyer($acc);

    wpPropose($this, $buyer, wpBody($acc, 'ad', $ad->external_id, 'active'), 'key-a-0001')->assertCreated();
    wpPropose($this, $buyer, wpBody($acc, 'ad', $ad->external_id, 'active'), 'key-b-0001')->assertCreated();
    wpPropose($this, $buyer, wpBody($acc, 'ad', $ad->external_id, 'paused'), 'key-c-0001')->assertCreated();

    expect(AdWriteAction::where('state', 'proposed')->count())->toBe(3)
        ->and(AdWriteAction::whereNotNull('open_business_key')->count())->toBe(0);
});

it('refuses a missing or malformed Idempotency-Key and a bad body with 422', function (?string $key, array $over) {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $admin = User::factory()->adsAuthority()->create(['role' => UserRole::Admin]);

    wpPropose($this, $admin, array_replace_recursive(wpBody($acc, 'ad', $ad->external_id), $over), $key)
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    expect(AdWriteAction::count())->toBe(0);
})->with([
    'missing key' => [null, []],
    'short key' => ['abc', []],
    'bad chars' => ['key with spaces', []],
    'bad level' => ['key-0001', ['target' => ['level' => 'account']]],
    'bad to' => ['key-0001', ['params' => ['to' => 'deleted']]],
    'bad type' => ['key-0001', ['type' => 'set_budget']],
    'unknown account' => ['key-0001', ['account_id' => 999999]],
]);

it('refuses a supervisor without Ads authority at campaign level: 403, no action row, one write.refused audit row', function () {
    $acc = AdAccount::factory()->meta()->create();
    $camp = AdCampaign::factory()->for($acc, 'account')->create();
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);

    wpPropose($this, $sup, wpBody($acc, 'campaign', $camp->external_id))->assertForbidden()
        ->assertJsonPath('code', 'ads_authority_required')->assertJsonPath('details.level', 'campaign');

    expect(AdWriteAction::count())->toBe(0);
    $audit = AdsAuditLog::where('action', 'write.refused')->sole();
    expect($audit->meta['code'])->toBe('ads_authority_required')->and($audit->meta['level'])->toBe('campaign')
        ->and($audit->meta['external_id'])->toBe($camp->external_id)->and($audit->meta['to'])->toBe('paused')
        ->and($audit->ad_account_id)->toBe($acc->id)->and($audit->actor_user_id)->toBe($sup->id);
});

it('refuses an unknown target with 404 not_found and audits it', function () {
    $acc = AdAccount::factory()->meta()->create();
    $admin = User::factory()->adsAuthority()->create(['role' => UserRole::Admin]);

    wpPropose($this, $admin, wpBody($acc, 'ad', '424242'))->assertNotFound()->assertJsonPath('code', 'not_found');
    expect(AdWriteAction::count())->toBe(0)->and(AdsAuditLog::where('action', 'write.refused')->count())->toBe(1);
});

it('refuses a Run proposal while the kill switch is off but records a Stop', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $buyer = wpBuyer($acc);
    app(AdsSettings::class)->set('writes_enabled', false);

    wpPropose($this, $buyer, wpBody($acc, 'ad', $ad->external_id, 'active'), 'key-run-01')->assertStatus(503)->assertJsonPath('code', 'writes_disabled');
    wpPropose($this, $buyer, wpBody($acc, 'ad', $ad->external_id, 'paused'), 'key-stop-1')->assertCreated();
});

it('shows an action to its proposer and to supervisors, 404 to another buyer', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $buyer = wpBuyer($acc);
    $id = wpPropose($this, $buyer, wpBody($acc, 'ad', $ad->external_id))->json('action.id');
    $other = wpBuyer(AdAccount::factory()->meta()->create());
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);

    $this->actingAs($buyer)->getJson("/ads/write-actions/{$id}")->assertOk()
        ->assertJsonPath('action.id', $id)->assertJsonPath('action.state', 'proposed')
        ->assertJsonPath('steps', [])->assertJsonPath('timeline.0.action', 'write.proposed');
    $this->actingAs($sup)->getJson("/ads/write-actions/{$id}")->assertOk();
    $this->actingAs($other)->getJson("/ads/write-actions/{$id}")->assertNotFound()->assertJsonPath('code', 'not_found');
    $this->actingAs($buyer)->getJson('/ads/write-actions/01NOTANACTION0000000000000')->assertNotFound();
});

it('expires a proposal lazily after the TTL', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $buyer = wpBuyer($acc);
    $id = wpPropose($this, $buyer, wpBody($acc, 'ad', $ad->external_id))->json('action.id');

    $this->travel(11)->minutes();

    $this->actingAs($buyer)->getJson("/ads/write-actions/{$id}")->assertOk()->assertJsonPath('action.state', 'expired');
    expect(AdWriteAction::sole()->state)->toBe('expired')
        ->and(AdsAuditLog::where('action', 'write.expired')->count())->toBe(1);

    $this->actingAs($buyer)->getJson("/ads/write-actions/{$id}")->assertOk();
    expect(AdsAuditLog::where('action', 'write.expired')->count())->toBe(1); // once
});

it('is closed to content users like every Ads report', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $content = User::factory()->create(['role' => UserRole::Content]);

    wpPropose($this, $content, wpBody($acc, 'ad', $ad->external_id))->assertForbidden();
    expect(AdWriteAction::count())->toBe(0);
});
