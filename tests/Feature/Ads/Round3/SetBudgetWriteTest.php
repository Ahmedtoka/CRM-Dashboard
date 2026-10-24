<?php

use App\Ads\Platforms\AdPlatform;
use App\Ads\Platforms\DriverFactory;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdCampaign;
use App\Models\AdSet;
use App\Models\AdWriteAction;
use App\Models\AdWriteStep;
use App\Models\MediaBuyer;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Round 3: the «scale» decision = a daily budget change by a percentage, through the same propose → confirm pipeline. */
beforeEach(function () {
    Http::preventStrayRequests();
    Cache::forget('ads-fake-writer');
    FakeAdsDriver::reset();
    config(['crm.ads.drivers.meta' => 'fake', 'crm.ads.drivers.tiktok' => 'fake', 'crm.ads.drivers.google' => 'fake', 'crm.ads.write_sandbox_accounts' => [], 'crm.ads.execute' => true]);
});

/** @return array{acc: AdAccount, camp: AdCampaign, set: AdSet, ad: Ad, admin: User} */
function sbWorld(string $platform = 'meta'): array
{
    $acc = AdAccount::factory()->{$platform}()->create(['currency' => 'EGP']);
    $camp = AdCampaign::factory()->for($acc, 'account')->create(['external_id' => '9100', 'status' => 'ACTIVE']);
    $set = AdSet::factory()->create(['ad_campaign_id' => $camp->id, 'external_id' => '9200', 'status' => 'ACTIVE']);
    $ad = Ad::factory()->for($acc, 'account')->create(['ad_campaign_id' => $camp->id, 'ad_set_id' => $set->id, 'external_id' => '9300', 'status' => 'ACTIVE']);

    return ['acc' => $acc, 'camp' => $camp, 'set' => $set, 'ad' => $ad, 'admin' => User::factory()->adsAuthority()->create(['role' => UserRole::Admin])];
}

function sbPropose($test, User $u, AdAccount $acc, string $level, string $id, int $pct, ?string $key = null)
{
    return $test->actingAs($u)->postJson('/ads/write-actions', [
        'type' => 'set_budget', 'account_id' => $acc->id, 'target' => ['level' => $level, 'external_id' => $id], 'params' => ['pct' => $pct], 'reason' => 'winner',
    ], ['Idempotency-Key' => $key ?? 'sb-'.bin2hex(random_bytes(8))]);
}

function sbBudgets(): array
{
    return Cache::get('ads-fake-writer')['budgets'] ?? [];
}

function sbSeed(string $platform, string $level, string $id, array $fields): void
{
    app(DriverFactory::class)->writer(AdPlatform::from($platform))->seedObject($level, $id, $fields);
}

it('proposes a +20% raise on the ad set that holds the budget of an ad, with the exact before and after amounts', function () {
    $w = sbWorld();

    $res = sbPropose($this, $w['admin'], $w['acc'], 'ad', '9300', 20)->assertCreated();

    // The fake ad set carries 50,000 minor (EGP 500.00); the ad itself has no budget, so the write targets its ad set.
    $res->assertJsonPath('action.type', 'set_budget')->assertJsonPath('action.level', 'adset')->assertJsonPath('action.external_id', '9200')
        ->assertJsonPath('action.state', 'proposed')->assertJsonPath('diff.0.path', 'daily_budget')->assertJsonPath('diff.0.level', 'adset')
        ->assertJsonPath('diff.0.before.minor', 50000)->assertJsonPath('diff.0.after.minor', 60000)->assertJsonPath('diff.0.before.currency', 'EGP')
        ->assertJsonPath('action.params.pct', 20)->assertJsonPath('action.params.from_minor', 50000)->assertJsonPath('action.params.to_minor', 60000);
    expect(sbBudgets())->toBe([]); // a proposal never calls the platform
});

it('confirms a budget raise: one platform call with the new amount, a step with before/after, and the audit trail', function () {
    $w = sbWorld();
    $id = sbPropose($this, $w['admin'], $w['acc'], 'ad', '9300', 20)->json('action.id');
    $x = AdWriteAction::where('public_id', $id)->sole();

    $this->actingAs($w['admin'])->postJson("/ads/write-actions/{$x->public_id}/confirm", ['diff_hash' => $x->diff_hash])
        ->assertOk()->assertJsonPath('action.state', 'succeeded')->assertJsonPath('message', __('ads.flash.budget_changed'));

    expect(sbBudgets())->toBe([['level' => 'adset', 'id' => '9200', 'minor' => 60000]]);
    $step = AdWriteStep::sole();
    expect($step->op)->toBe('set_budget')->and($step->before)->toBe(['daily_budget_minor' => 50000])->and($step->after)->toBe(['daily_budget_minor' => 60000]);
    expect($x->fresh()->confirmed_by_id)->toBe($w['admin']->id)->and($x->fresh()->open_business_key)->toBeNull();
});

it('undo of a budget change proposes the old amount back and marks the first action rolled back', function () {
    $w = sbWorld();
    $x = AdWriteAction::where('public_id', sbPropose($this, $w['admin'], $w['acc'], 'ad', '9300', 20)->json('action.id'))->sole();
    $this->actingAs($w['admin'])->postJson("/ads/write-actions/{$x->public_id}/confirm", ['diff_hash' => $x->diff_hash])->assertOk();

    $undo = $this->actingAs($w['admin'])->postJson("/ads/write-actions/{$x->public_id}/rollback", ['reason' => 'too early'], ['Idempotency-Key' => 'sb-undo-1'])
        ->assertCreated()->assertJsonPath('action.type', 'set_budget')->assertJsonPath('diff.0.before.minor', 60000)->assertJsonPath('diff.0.after.minor', 50000)
        ->assertJsonPath('action.rollback_of', $x->public_id);
    $u = AdWriteAction::where('public_id', $undo->json('action.id'))->sole();
    $this->actingAs($w['admin'])->postJson("/ads/write-actions/{$u->public_id}/confirm", ['diff_hash' => $u->diff_hash])->assertOk()->assertJsonPath('action.state', 'succeeded');

    expect(sbBudgets())->toHaveCount(2)->and(sbBudgets()[1]['minor'])->toBe(50000)->and($x->fresh()->state)->toBe('rolled_back');
});

it('a cut (-30%) on a campaign with its own budget targets the campaign itself', function () {
    $w = sbWorld();
    sbSeed('meta', 'campaign', '9100', ['dailyBudgetMinor' => 100000]);

    sbPropose($this, $w['admin'], $w['acc'], 'campaign', '9100', -30)->assertCreated()
        ->assertJsonPath('action.level', 'campaign')->assertJsonPath('diff.0.before.minor', 100000)->assertJsonPath('diff.0.after.minor', 70000);
});

it('refuses a raise that would pass the daily budget cap, before any platform call', function () {
    $w = sbWorld();
    // The fake live read of the AD lists its parents: the ad set holds EGP 19,000 a day; +20% passes the EGP 20,000 cap.
    sbSeed('meta', 'ad', '9300', ['parents' => [
        ['level' => 'adset', 'status' => 'ACTIVE', 'dailyBudgetMinor' => 1900000, 'lifetimeBudgetMinor' => null, 'endsAt' => null],
        ['level' => 'campaign', 'status' => 'ACTIVE', 'dailyBudgetMinor' => null, 'lifetimeBudgetMinor' => null, 'endsAt' => null],
    ]]);

    sbPropose($this, $w['admin'], $w['acc'], 'ad', '9300', 20)->assertStatus(422)->assertJsonPath('code', 'budget_over_cap');
    expect(AdWriteAction::count())->toBe(0)->and(sbBudgets())->toBe([]);
});

it('refuses a budget change when no object in the chain has a daily budget', function () {
    $w = sbWorld();
    sbSeed('meta', 'ad', '9300', ['parents' => [
        ['level' => 'adset', 'status' => 'ACTIVE', 'dailyBudgetMinor' => null, 'lifetimeBudgetMinor' => 900000, 'endsAt' => null],
        ['level' => 'campaign', 'status' => 'ACTIVE', 'dailyBudgetMinor' => null, 'lifetimeBudgetMinor' => null, 'endsAt' => null],
    ]]);

    sbPropose($this, $w['admin'], $w['acc'], 'ad', '9300', 20)->assertStatus(422)->assertJsonPath('code', 'budget_unreadable');
});

it('a media buyer without Ads authority cannot change budgets (they live above the ad level)', function () {
    $w = sbWorld();
    $user = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $buyer = MediaBuyer::factory()->create(['user_id' => $user->id]);
    AdAccountAssignment::factory()->create(['ad_account_id' => $w['acc']->id, 'media_buyer_id' => $buyer->id, 'starts_on' => '2026-01-01', 'ends_on' => null]);

    sbPropose($this, $user, $w['acc'], 'ad', '9300', 20)->assertStatus(403)->assertJsonPath('code', 'ads_authority_required');
});

it('Google is read-only and TikTok is at parity with Meta', function () {
    $g = sbWorld('google');
    sbPropose($this, $g['admin'], $g['acc'], 'ad', '9300', 20)->assertStatus(422)->assertJsonPath('code', 'platform_not_writable');

    $t = sbWorld('tiktok');
    $x = AdWriteAction::where('public_id', sbPropose($this, $t['admin'], $t['acc'], 'ad', '9300', 20)->json('action.id'))->sole();
    $this->actingAs($t['admin'])->postJson("/ads/write-actions/{$x->public_id}/confirm", ['diff_hash' => $x->diff_hash])->assertOk()->assertJsonPath('action.state', 'succeeded');
    expect(sbBudgets())->toBe([['level' => 'adset', 'id' => '9200', 'minor' => 60000]]);
});

it('validates the percentage: a whole number between -90 and +200, never zero', function () {
    $w = sbWorld();
    sbPropose($this, $w['admin'], $w['acc'], 'ad', '9300', 0)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    sbPropose($this, $w['admin'], $w['acc'], 'ad', '9300', 500)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});

it('with the execute flag off a budget change is a dry run that reports what it would have sent', function () {
    config(['crm.ads.execute' => false]);
    $w = sbWorld();
    $x = AdWriteAction::where('public_id', sbPropose($this, $w['admin'], $w['acc'], 'ad', '9300', 20)->json('action.id'))->sole();

    $this->actingAs($w['admin'])->postJson("/ads/write-actions/{$x->public_id}/confirm", ['diff_hash' => $x->diff_hash])
        ->assertOk()->assertJsonPath('action.state', 'dry_run')
        ->assertJsonPath('action.outcome.would', ['op' => 'set_budget', 'level' => 'adset', 'external_id' => '9200', 'after' => ['daily_budget_minor' => 60000]]);
    expect(sbBudgets())->toBe([]);
});
