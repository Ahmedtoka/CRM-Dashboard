<?php

use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdCampaign;
use App\Models\AdPlatformConnection;
use App\Models\AdsAuditLog;
use App\Models\AdSet;
use App\Models\AdWriteAction;
use App\Models\MediaBuyer;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::forget('ads-fake-writer');
    FakeAdsDriver::reset();
    config(['crm.ads.drivers.meta' => 'fake', 'crm.ads.drivers.tiktok' => 'fake', 'crm.ads.drivers.google' => 'fake', 'crm.ads.write_sandbox_accounts' => []]);
});

function rpBuyer(AdAccount $acc): User
{
    $user = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $buyer = MediaBuyer::factory()->create(['user_id' => $user->id]);
    AdAccountAssignment::factory()->create(['ad_account_id' => $acc->id, 'media_buyer_id' => $buyer->id, 'starts_on' => '2026-01-01', 'ends_on' => null]);

    return $user;
}

function rpPropose($test, User $u, AdAccount $acc, string $level, string $id, string $to)
{
    return $test->actingAs($u)->postJson('/ads/write-actions', [
        'type' => 'set_status', 'account_id' => $acc->id, 'target' => ['level' => $level, 'external_id' => $id], 'params' => ['to' => $to],
    ], ['Idempotency-Key' => 'k-'.bin2hex(random_bytes(8))]);
}

function rpAction($res): AdWriteAction
{
    $res->assertSuccessful();

    return AdWriteAction::where('public_id', $res->json('action.id'))->sole();
}

function rpConfirm($test, User $u, AdWriteAction $x)
{
    return $test->actingAs($u)->postJson("/ads/write-actions/{$x->public_id}/confirm", ['diff_hash' => $x->diff_hash]);
}

function rpReads(): int
{
    return count(Cache::get('ads-fake-writer')['reads'] ?? []);
}

function rpStatuses(): array
{
    return Cache::get('ads-fake-writer')['statuses'] ?? [];
}

it('puts the live status in the Run diff, not the local row', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'ACTIVE']);
    app(FakeAdsDriver::class)->seedObject('ad', $ad->external_id, ['status' => 'PAUSED']);

    $x = rpAction(rpPropose($this, rpBuyer($acc), $acc, 'ad', $ad->external_id, 'active'));

    expect($x->diff[0])->toBe(['path' => 'status', 'before' => 'PAUSED', 'after' => 'ACTIVE'])
        ->and($x->from_status)->toBe('PAUSED')
        ->and($x->expected['status'])->toBe('PAUSED')
        ->and($x->expected['read_at'])->not->toBeNull()
        ->and(rpReads())->toBe(1);
});

it('reuses a fresh propose-time read at confirm (1 read in all)', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $buyer = rpBuyer($acc);
    $x = rpAction(rpPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'active'));

    $this->travel(59)->seconds();
    rpConfirm($this, $buyer, $x)->assertOk()->assertJsonPath('action.state', 'succeeded');

    expect(rpReads())->toBe(1)->and(rpStatuses())->toHaveCount(1);
});

it('reads again at confirm when the propose-time read is older than 60 s', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $buyer = rpBuyer($acc);
    $x = rpAction(rpPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'active'));

    $this->travel(61)->seconds();
    rpConfirm($this, $buyer, $x)->assertOk()->assertJsonPath('action.state', 'succeeded');

    expect(rpReads())->toBe(2)->and(rpStatuses())->toHaveCount(1);
});

it('a Run on something already ACTIVE at confirm succeeds as a noop with no platform call', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $buyer = rpBuyer($acc);
    $x = rpAction(rpPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'active'));
    app(FakeAdsDriver::class)->seedObject('ad', $ad->external_id, ['status' => 'ACTIVE']);

    $this->travel(61)->seconds();
    rpConfirm($this, $buyer, $x)->assertOk()
        ->assertJsonPath('action.state', 'succeeded')
        ->assertJsonPath('action.outcome.noop', true)
        ->assertJsonPath('message', __('ads.write.notes.already_active'));

    expect(rpStatuses())->toBe([])
        ->and($x->fresh()->open_business_key)->toBeNull()
        ->and($x->steps()->count())->toBe(0)
        ->and($ad->fresh()->status)->toBe('ACTIVE');
});

it('supersedes the Run with 409 precondition_failed when the live status is no longer the expected one', function (string $live) {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $buyer = rpBuyer($acc);
    $x = rpAction(rpPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'active'));
    app(FakeAdsDriver::class)->seedObject('ad', $ad->external_id, ['status' => $live]);

    $this->travel(61)->seconds();
    rpConfirm($this, $buyer, $x)->assertStatus(409)
        ->assertJsonPath('code', 'precondition_failed')
        ->assertJsonPath('details.expected', 'PAUSED')
        ->assertJsonPath('details.actual', $live);

    expect($x->fresh()->state)->toBe('superseded')
        ->and($x->fresh()->error_code)->toBe('precondition_failed')
        ->and(rpStatuses())->toBe([])
        ->and(AdsAuditLog::where('action', 'write.superseded')->where('subject_id', $x->id)->count())->toBe(1);
})->with(['ARCHIVED', 'DELETED', 'IN_PROCESS']);

it('a throttled pre-read at propose answers 429 and stores nothing', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    FakeAdsDriver::failNext('readObject', 'rate');

    rpPropose($this, rpBuyer($acc), $acc, 'ad', $ad->external_id, 'active')
        ->assertStatus(429)->assertJsonPath('code', 'rate_limited')->assertHeader('Retry-After', '120');

    expect(AdWriteAction::count())->toBe(0)
        ->and(AdsAuditLog::where('action', 'write.refused')->value('meta')['code'])->toBe('rate_limited');
});

it('an unreadable object at a stale confirm fails the Run closed', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $buyer = rpBuyer($acc);
    $x = rpAction(rpPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'active'));
    FakeAdsDriver::failNext('readObject', 'unreachable_before');

    $this->travel(61)->seconds();
    rpConfirm($this, $buyer, $x)->assertStatus(422)->assertJsonPath('code', 'budget_unreadable');

    expect($x->fresh()->state)->toBe('failed')->and($x->fresh()->error_code)->toBe('budget_unreadable')->and(rpStatuses())->toBe([]);
});

it('refuses a TikTok Run (no live read yet) but lets a TikTok Stop through', function () {
    config(['crm.ads.drivers.tiktok' => 'live']);
    $conn = AdPlatformConnection::factory()->tiktok()->create(['credentials' => ['access_token' => 'tt-secret', 'advertiser_ids' => ['7001']]]);
    $acc = AdAccount::factory()->tiktok()->create(['external_id' => '7001', 'connection_id' => $conn->id]);
    config(['crm.ads.write_sandbox_accounts' => ['7001']]);
    $paused = Ad::factory()->for($acc, 'account')->create(['status' => 'PAUSED', 'external_id' => '4401']);
    $active = Ad::factory()->for($acc, 'account')->create(['status' => 'ACTIVE', 'external_id' => '4402']);
    $buyer = rpBuyer($acc);
    Http::fake(['business-api.tiktok.com/open_api/v1.3/ad/status/update/' => Http::response(['code' => 0, 'message' => 'OK', 'data' => []])]);

    rpPropose($this, $buyer, $acc, 'ad', $paused->external_id, 'active')->assertStatus(422)->assertJsonPath('code', 'budget_unreadable');
    expect(AdWriteAction::count())->toBe(0);

    $stop = rpAction(rpPropose($this, $buyer, $acc, 'ad', $active->external_id, 'paused'));
    rpConfirm($this, $buyer, $stop)->assertOk()->assertJsonPath('action.state', 'succeeded');
    Http::assertSentCount(1);
});

it('a Stop never pre-reads', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'ACTIVE']);
    $buyer = rpBuyer($acc);
    FakeAdsDriver::failNext('readObject', 'unreachable_before', 5);

    $x = rpAction(rpPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'paused'));
    $this->travel(61)->seconds();
    rpConfirm($this, $buyer, $x)->assertOk()->assertJsonPath('action.state', 'succeeded');

    expect(rpReads())->toBe(0)->and($x->fresh()->expected['read_at'])->toBeNull();
});

it('ads:write-preview reads once, prints the budgets, writes nothing and never prints a token', function () {
    $conn = AdPlatformConnection::factory()->meta()->create(['credentials' => ['access_token' => 'EAATESTTOKEN123']]);
    $acc = AdAccount::factory()->meta()->create(['connection_id' => $conn->id, 'external_id' => 'act_555']);
    $campaign = AdCampaign::factory()->create(['ad_account_id' => $acc->id]);
    $set = AdSet::factory()->create(['ad_campaign_id' => $campaign->id, 'status' => 'PAUSED']);
    $auditBefore = AdsAuditLog::count();

    $code = Artisan::call('ads:write-preview', ['--account' => 'act_555', '--level' => 'adset', '--id' => $set->external_id]);
    $out = Artisan::output();

    expect($code)->toBe(0)
        ->and($out)->toContain('PAUSED')
        ->toContain('50000 minor (EGP 500.00)')
        ->not->toContain('EAATESTTOKEN123')
        ->and(AdWriteAction::count())->toBe(0)
        ->and(AdsAuditLog::count())->toBe($auditBefore)
        ->and(rpReads())->toBe(1);
});

it('ads:write-preview refuses an unknown account, level or object', function () {
    $acc = AdAccount::factory()->meta()->create();

    expect(Artisan::call('ads:write-preview', ['--account' => 'act_nope', '--level' => 'ad', '--id' => '1']))->toBe(1)
        ->and(Artisan::call('ads:write-preview', ['--account' => (string) $acc->id, '--level' => 'account', '--id' => '1']))->toBe(1)
        ->and(Artisan::call('ads:write-preview', ['--account' => (string) $acc->id, '--level' => 'ad', '--id' => '404']))->toBe(1);
});
