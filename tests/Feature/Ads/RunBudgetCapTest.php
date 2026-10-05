<?php

use App\Ads\Control\Write\RunGuard;
use App\Ads\Control\Write\WriteActionService;
use App\Ads\Control\Write\WriteDenied;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdCampaign;
use App\Models\AdSet;
use App\Models\AdWriteAction;
use App\Models\MediaBuyer;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::forget('ads-fake-writer');
    FakeAdsDriver::reset();
    config(['crm.ads.drivers.meta' => 'fake', 'crm.ads.drivers.tiktok' => 'fake', 'crm.ads.drivers.google' => 'fake', 'crm.ads.write_sandbox_accounts' => []]);
    $this->freezeTime();
    $this->acc = AdAccount::factory()->meta()->create();
    $this->campaign = AdCampaign::factory()->create(['ad_account_id' => $this->acc->id, 'status' => 'ACTIVE']);
    $this->set = AdSet::factory()->create(['ad_campaign_id' => $this->campaign->id, 'status' => 'PAUSED']);
    $this->ad = Ad::factory()->for($this->acc, 'account')->create([
        'status' => 'PAUSED', 'ad_set_id' => $this->set->id, 'ad_campaign_id' => $this->campaign->id,
    ]);
    $this->admin = User::factory()->adsAuthority()->create(['role' => UserRole::Admin]);
});

function bcBuyer(AdAccount $acc): User
{
    $user = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $buyer = MediaBuyer::factory()->create(['user_id' => $user->id]);
    AdAccountAssignment::factory()->create(['ad_account_id' => $acc->id, 'media_buyer_id' => $buyer->id, 'starts_on' => '2026-01-01', 'ends_on' => null]);

    return $user;
}

function bcPropose($test, User $u, AdAccount $acc, string $level, string $id, string $to = 'active')
{
    return $test->actingAs($u)->postJson('/ads/write-actions', [
        'type' => 'set_status', 'account_id' => $acc->id, 'target' => ['level' => $level, 'external_id' => $id], 'params' => ['to' => $to],
    ], ['Idempotency-Key' => 'k-'.bin2hex(random_bytes(8))]);
}

function bcSeed(string $level, string $id, array $fields): void
{
    app(FakeAdsDriver::class)->seedObject($level, $id, $fields);
}

/** Ad parents: the ad set, then the campaign. */
function bcAdParents(?int $setDaily, ?int $campaignDaily = null, ?int $setLifetime = null, $setEnds = null): array
{
    return ['parents' => [
        ['level' => 'adset', 'status' => 'ACTIVE', 'dailyBudgetMinor' => $setDaily, 'lifetimeBudgetMinor' => $setLifetime, 'endsAt' => $setEnds],
        ['level' => 'campaign', 'status' => 'ACTIVE', 'dailyBudgetMinor' => $campaignDaily, 'lifetimeBudgetMinor' => null, 'endsAt' => null],
    ]];
}

function bcStatuses(): array
{
    return Cache::get('ads-fake-writer')['statuses'] ?? [];
}

it('allows an ad set Run under the cap and records the evaluated cap', function () {
    bcSeed('adset', $this->set->external_id, ['dailyBudgetMinor' => 1500000]);

    $res = bcPropose($this, $this->admin, $this->acc, 'adset', $this->set->external_id)->assertCreated();

    $row = collect($res->json('limits_checked'))->firstWhere('key', 'max_daily_budget');
    expect($row)->toMatchArray(['key' => 'max_daily_budget', 'level' => 'adset', 'object_id' => $this->set->external_id,
        'limit' => 2000000, 'requested' => 1500000, 'currency' => 'EGP', 'outcome' => 'ok'])
        ->and(AdWriteAction::sole()->limits_checked)->toContain($row);
});

it('refuses an ad set Run above the cap at propose, Ads authority included', function () {
    bcSeed('adset', $this->set->external_id, ['dailyBudgetMinor' => 2500000]);

    bcPropose($this, $this->admin, $this->acc, 'adset', $this->set->external_id)->assertStatus(422)
        ->assertJsonPath('code', 'budget_over_cap')
        ->assertJsonPath('details.object_level', 'adset')
        ->assertJsonPath('details.object_id', $this->set->external_id)
        ->assertJsonPath('details.per_day_minor', 2500000)
        ->assertJsonPath('details.cap_minor', 2000000)
        ->assertJsonPath('details.currency', 'EGP')
        ->assertJsonPath('message', __('ads.errors.budget_over_cap', ['per_day' => '25,000.00', 'cap' => '20,000.00', 'currency' => 'EGP']));

    expect(AdWriteAction::count())->toBe(0);
});

it('refuses an ad Run on the CBO campaign budget when the ad set has none', function () {
    bcSeed('ad', $this->ad->external_id, bcAdParents(null, 3000000));

    bcPropose($this, bcBuyer($this->acc), $this->acc, 'ad', $this->ad->external_id)->assertStatus(422)
        ->assertJsonPath('code', 'budget_over_cap')
        ->assertJsonPath('details.object_level', 'campaign')
        ->assertJsonPath('details.object_id', $this->campaign->external_id)
        ->assertJsonPath('details.per_day_minor', 3000000);
});

it('checks both the ad set and the CBO campaign of an ad', function () {
    bcSeed('ad', $this->ad->external_id, bcAdParents(100000, 1900000));

    $res = bcPropose($this, bcBuyer($this->acc), $this->acc, 'ad', $this->ad->external_id)->assertCreated();

    expect(collect($res->json('limits_checked'))->where('key', 'max_daily_budget')->pluck('requested', 'level')->all())
        ->toBe(['adset' => 100000, 'campaign' => 1900000]);
});

it('spreads a lifetime budget over the days until its end', function (int $days, int $status, int $perDay) {
    bcSeed('ad', $this->ad->external_id, bcAdParents(null, null, 30000000, now()->addDays($days)->toImmutable()));

    $res = bcPropose($this, bcBuyer($this->acc), $this->acc, 'ad', $this->ad->external_id)->assertStatus($status);

    if ($status === 422) {
        $res->assertJsonPath('code', 'budget_over_cap')->assertJsonPath('details.per_day_minor', $perDay);
    } else {
        expect(collect($res->json('limits_checked'))->firstWhere('key', 'max_daily_budget')['requested'])->toBe($perDay);
    }
})->with([
    'ends in 10 days: 3,000,000 a day' => [10, 422, 3000000],
    'ends in 20 days: 1,500,000 a day' => [20, 201, 1500000],
    'already ended: the whole budget' => [-1, 422, 30000000],
]);

it('refuses a lifetime budget with no end time as unreadable', function () {
    bcSeed('ad', $this->ad->external_id, bcAdParents(null, null, 30000000, null));

    bcPropose($this, bcBuyer($this->acc), $this->acc, 'ad', $this->ad->external_id)->assertStatus(422)->assertJsonPath('code', 'budget_unreadable');
});

it('refuses when no relevant object has any budget', function () {
    bcSeed('ad', $this->ad->external_id, bcAdParents(null, null));

    bcPropose($this, bcBuyer($this->acc), $this->acc, 'ad', $this->ad->external_id)->assertStatus(422)->assertJsonPath('code', 'budget_unreadable');
});

it('refuses an account in another currency than the cap, never converting', function () {
    $this->acc->update(['currency' => 'USD']);

    bcPropose($this, bcBuyer($this->acc), $this->acc, 'ad', $this->ad->external_id)->assertStatus(422)
        ->assertJsonPath('code', 'currency_mismatch')
        ->assertJsonPath('details.currency', 'USD')
        ->assertJsonPath('details.cap_currency', 'EGP');
});

it('a user override raises the cap for that user only', function () {
    bcSeed('ad', $this->ad->external_id, bcAdParents(2500000));
    $owner = bcBuyer($this->acc);
    $other = bcBuyer($this->acc);
    Artisan::call('ads:write-limits', ['--user' => (string) $owner->id, '--set' => ['max_daily_budget_minor=5000000']]);

    bcPropose($this, $owner, $this->acc, 'ad', $this->ad->external_id)->assertCreated();
    bcPropose($this, $other, $this->acc, 'ad', $this->ad->external_id)->assertStatus(422)->assertJsonPath('code', 'budget_over_cap');
});

it('re-checks the budget on a stale confirm: raised meanwhile, the Run fails with no platform call', function () {
    $buyer = bcBuyer($this->acc);
    $res = bcPropose($this, $buyer, $this->acc, 'ad', $this->ad->external_id)->assertCreated();
    $x = AdWriteAction::where('public_id', $res->json('action.id'))->sole();
    bcSeed('ad', $this->ad->external_id, bcAdParents(2500000));

    $this->travel(61)->seconds();
    $this->actingAs($buyer)->postJson("/ads/write-actions/{$x->public_id}/confirm", ['diff_hash' => $x->diff_hash])
        ->assertStatus(422)->assertJsonPath('code', 'budget_over_cap')->assertJsonPath('details.per_day_minor', 2500000);

    expect($x->fresh()->state)->toBe('failed')->and($x->fresh()->error_code)->toBe('budget_over_cap')->and(bcStatuses())->toBe([]);
});

it('never checks a Stop', function () {
    $this->set->update(['status' => 'ACTIVE']);
    bcSeed('adset', $this->set->external_id, ['dailyBudgetMinor' => 9000000]);
    $this->acc->update(['currency' => 'USD']);

    $res = bcPropose($this, $this->admin, $this->acc, 'adset', $this->set->external_id, 'paused')->assertCreated();
    $x = AdWriteAction::where('public_id', $res->json('action.id'))->sole();
    $this->actingAs($this->admin)->postJson("/ads/write-actions/{$x->public_id}/confirm", ['diff_hash' => $x->diff_hash])
        ->assertOk()->assertJsonPath('action.state', 'succeeded');

    expect($x->fresh()->limits_checked)->toBe([]);
});

it('ads:write-preview prints the cap verdict and executes no write statement', function () {
    bcSeed('adset', $this->set->external_id, ['dailyBudgetMinor' => 2500000]);
    $writes = [];
    DB::listen(function ($q) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $q->sql)) {
            $writes[] = $q->sql;
        }
    });

    Artisan::call('ads:write-preview', ['--account' => (string) $this->acc->id, '--level' => 'adset', '--id' => $this->set->external_id]);
    $out = Artisan::output();

    expect($out)->toContain('2500000 minor (EGP 25,000.00)')
        ->toContain('cap verdict: refused budget_over_cap')
        ->toContain('2000000 minor (EGP 20,000.00)')
        ->and($writes)->toBe([]);

    bcSeed('adset', $this->set->external_id, ['dailyBudgetMinor' => 1000]);
    Artisan::call('ads:write-preview', ['--account' => (string) $this->acc->id, '--level' => 'adset', '--id' => $this->set->external_id]);
    expect(Artisan::output())->toContain('cap verdict: allowed');
});

it('explains a missing budget (ABO campaign) instead of asking to retry', function () {
    bcSeed('ad', $this->ad->external_id, bcAdParents(null, null));

    bcPropose($this, bcBuyer($this->acc), $this->acc, 'ad', $this->ad->external_id)->assertStatus(422)
        ->assertJsonPath('code', 'budget_unreadable')
        ->assertJsonPath('details.reason', 'no_budget')
        ->assertJsonPath('message', __('ads.errors.budget_unreadable_no_budget'));
    expect(__('ads.errors.budget_unreadable_no_budget'))->not->toBe(__('ads.errors.budget_unreadable'));
    app()->setLocale('ar');
    expect(__('ads.errors.budget_unreadable_no_budget'))->not->toBe('ads.errors.budget_unreadable_no_budget');
});

it('fails closed when the account currency is unknown, never assuming EGP', function (?string $currency) {
    $this->acc->update(['currency' => $currency]);

    bcPropose($this, bcBuyer($this->acc), $this->acc, 'ad', $this->ad->external_id)->assertStatus(422)
        ->assertJsonPath('code', 'budget_unreadable')
        ->assertJsonPath('details.reason', 'currency_unknown')
        ->assertJsonPath('message', __('ads.errors.budget_unreadable_currency_unknown'));
})->with(['empty' => [''], 'null' => [null]]);

it('marks the currency row as mismatch', function () {
    $this->acc->update(['currency' => 'USD']);
    $live = app(FakeAdsDriver::class)->readObject($this->acc, 'ad', $this->ad->external_id);

    $verdict = app(RunGuard::class)->budgetVerdict(null, $this->acc, $this->ad, $live);

    expect($verdict['rows'][0])->toBe(['key' => 'cap_currency', 'limit' => 'EGP', 'requested' => 'USD', 'outcome' => 'mismatch'])
        ->and($verdict['refusal']->errorCode)->toBe('currency_mismatch');
});

it('a losing double-submit does not overwrite the winner\'s limits_checked or confirm read', function () {
    $buyer = bcBuyer($this->acc);
    $res = bcPropose($this, $buyer, $this->acc, 'ad', $this->ad->external_id)->assertCreated();
    $stale = AdWriteAction::where('public_id', $res->json('action.id'))->sole();
    $this->travel(61)->seconds();
    $this->actingAs($buyer)->postJson("/ads/write-actions/{$stale->public_id}/confirm", ['diff_hash' => $stale->diff_hash])->assertOk();
    $winner = $stale->fresh();
    bcSeed('ad', $this->ad->external_id, bcAdParents(1234567));
    $this->travel(5)->seconds();

    expect(fn () => app(WriteActionService::class)->confirm($buyer, $stale, $stale->diff_hash))->toThrow(WriteDenied::class);

    $after = $stale->fresh();
    expect($after->limits_checked)->toBe($winner->limits_checked)
        ->and($after->expected)->toBe($winner->expected)
        ->and(collect($after->limits_checked)->pluck('key')->all())->toContain('activations_per_user_day');
});
