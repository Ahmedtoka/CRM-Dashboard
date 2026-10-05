<?php

use App\Ads\Control\AdWriteService;
use App\Ads\Control\StopAdvisor;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Ads\Platforms\MissingPermission;
use App\Ads\Platforms\RateLimited;
use App\Ads\Reports\AdsFilter;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdAction;
use App\Models\AdCampaign;
use App\Models\AdDailyMetric;
use App\Models\AdMaterial;
use App\Models\AdSet;
use App\Models\MediaBuyer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::forget('ads-fake-writer');
    config(['crm.ads.drivers.meta' => 'fake', 'crm.ads.drivers.tiktok' => 'fake', 'crm.ads.drivers.google' => 'fake']);
    $this->withoutVite();
});

function actPost(array $over = []): array
{
    return $over + ['level' => 'ad', 'status' => 'paused', 'reason' => 'ROAS too low'];
}

function actBuyer(AdAccount $acc, ?string $endsOn = null): User
{
    $user = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $buyer = MediaBuyer::factory()->create(['user_id' => $user->id]);
    AdAccountAssignment::factory()->create(['ad_account_id' => $acc->id, 'media_buyer_id' => $buyer->id, 'starts_on' => '2026-01-01', 'ends_on' => $endsOn]);

    return $user;
}

function actDay(Ad $ad, string $date, array $over = []): void
{
    AdDailyMetric::factory()->create($over + [
        'ad_id' => $ad->id, 'ad_account_id' => $ad->ad_account_id, 'date' => $date,
        'spend' => 100, 'purchase_value' => 0, 'purchases' => 0, 'impressions' => 1000, 'clicks' => 20, 'reach' => 800,
    ]);
}

it('stops an ad: platform call, local status and the action row', function () {
    $acc = AdAccount::factory()->meta()->create(['name' => 'LV Main']);
    $ad = Ad::factory()->for($acc, 'account')->create(['name' => 'Tired ad']);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => $ad->external_id]))
        ->assertOk()->assertJsonPath('ok', true);

    expect(Cache::get('ads-fake-writer')['statuses'])->toBe([['level' => 'ad', 'id' => $ad->external_id, 'status' => 'paused']]);
    $ad->refresh();
    expect($ad->status)->toBe('PAUSED')->and($ad->effective_status)->toBe('ACTIVE'); // effective is left to the next sync
    $row = AdAction::first();
    expect($row->user_id)->toBe($admin->id)->and($row->platform)->toBe('meta')->and($row->ad_account_id)->toBe($acc->id)
        ->and($row->level)->toBe('ad')->and($row->name)->toBe('Tired ad')->and($row->from_status)->toBe('ACTIVE')
        ->and($row->to_status)->toBe('PAUSED')->and($row->reason)->toBe('ROAS too low')->and($row->result)->toBe('ok')->and($row->error)->toBeNull();
});

it('runs a campaign and an ad set again and updates their local status', function () {
    $acc = AdAccount::factory()->meta()->create();
    $camp = AdCampaign::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $set = AdSet::factory()->for($camp, 'campaign')->create(['status' => 'PAUSED']);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->postJson('/ads/actions/status', ['account_id' => $acc->id, 'level' => 'campaign', 'external_id' => $camp->external_id, 'status' => 'active'])->assertOk();
    $this->actingAs($admin)->postJson('/ads/actions/status', ['account_id' => $acc->id, 'level' => 'adset', 'external_id' => $set->external_id, 'status' => 'active'])->assertOk();

    expect($camp->refresh()->status)->toBe('ACTIVE')->and($set->refresh()->status)->toBe('ACTIVE')
        ->and(AdAction::pluck('to_status')->all())->toBe(['ACTIVE', 'ACTIVE'])
        ->and(AdAction::first()->from_status)->toBe('PAUSED');
});

it('lets a media buyer act on their own account only', function () {
    $mine = AdAccount::factory()->meta()->create();
    $other = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($mine, 'account')->create();
    $foreign = Ad::factory()->for($other, 'account')->create();
    $buyer = actBuyer($mine);

    $this->actingAs($buyer)->postJson('/ads/actions/status', actPost(['account_id' => $other->id, 'external_id' => $foreign->external_id]))->assertForbidden();
    expect(Cache::get('ads-fake-writer'))->toBeNull()->and(AdAction::count())->toBe(0)->and($foreign->refresh()->status)->toBe('ACTIVE');

    $this->actingAs($buyer)->postJson('/ads/actions/status', actPost(['account_id' => $mine->id, 'external_id' => $ad->external_id]))->assertOk();
    expect($ad->refresh()->status)->toBe('PAUSED');
});

it('refuses a buyer whose assignment ended before today', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $buyer = actBuyer($acc, '2026-02-01');

    $this->actingAs($buyer)->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => $ad->external_id]))->assertForbidden();
    expect(AdAction::count())->toBe(0);
});

it('refuses content users, moderators and inactive accounts', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    foreach ([UserRole::Content, UserRole::Moderator] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]))
            ->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => $ad->external_id]))->assertForbidden();
    }

    $acc->update(['is_active' => false]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => $ad->external_id]))->assertForbidden();

    expect(Cache::get('ads-fake-writer'))->toBeNull()->and(AdAction::count())->toBe(0);
});

it('answers 422 with the readable message and logs an error row when the platform refuses', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $double = Mockery::mock(FakeAdsDriver::class);
    $double->shouldReceive('setStatus')->once()->andThrow(new MissingPermission('Meta permission missing: ads_management'));
    app()->bind(FakeAdsDriver::class, fn () => $double);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => $ad->external_id]))
        ->assertStatus(422)->assertJsonValidationErrors('status')
        ->assertJsonPath('errors.status.0', 'Meta permission missing: ads_management');

    $row = AdAction::first();
    expect($row->result)->toBe('error')->and($row->error)->toBe('Meta permission missing: ads_management')->and($row->to_status)->toBe('PAUSED')
        ->and($ad->refresh()->status)->toBe('ACTIVE');
});

it('maps a rate limit to a readable message', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $double = Mockery::mock(FakeAdsDriver::class);
    $double->shouldReceive('setStatus')->once()->andThrow(new RateLimited('(#17) User request limit reached'));
    app()->bind(FakeAdsDriver::class, fn () => $double);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => $ad->external_id]))
        ->assertStatus(422)->assertJsonPath('errors.status.0', __('ads.errors.rate_limited'));
    expect(AdAction::first()->result)->toBe('error');
});

it('rejects an unknown target without calling the platform', function () {
    $acc = AdAccount::factory()->meta()->create();
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => 'nope']))->assertStatus(422);

    expect(Cache::get('ads-fake-writer'))->toBeNull()->and(AdAction::count())->toBe(0);
});

it('throws an authorization exception from the service outside scope', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $content = User::factory()->create(['role' => UserRole::Content]);

    expect(app(AdWriteService::class)->canWrite($content, $acc))->toBeFalse();
    expect(fn () => app(AdWriteService::class)->setStatus($content, $acc, 'ad', $ad->external_id, 'paused', null))
        ->toThrow(AuthorizationException::class);
});

it('suggests losers, fatigued and need-stop ads, not winners or paused ads', function () {
    $acc = AdAccount::factory()->meta()->create(['name' => 'LV Main']);
    $winner = Ad::factory()->for($acc, 'account')->create(['name' => 'Winner']);
    $loser = Ad::factory()->for($acc, 'account')->create(['name' => 'Loser']);
    $tired = Ad::factory()->for($acc, 'account')->create(['name' => 'Tired']);
    $paused = Ad::factory()->for($acc, 'account')->create(['name' => 'Paused loser', 'status' => 'PAUSED', 'effective_status' => 'PAUSED']);
    $nothing = Ad::factory()->for($acc, 'account')->create(['name' => 'No sales']);
    $stock = Ad::factory()->for($acc, 'account')->create(['name' => 'Out of stock']);
    $material = AdMaterial::factory()->create(['title' => 'Black abaya', 'status' => 'activated', 'need_stop_at' => now()]);
    $material->ads()->attach($stock->id);

    foreach (CarbonPeriod::create('2026-09-17', '2026-09-30') as $d) {
        $day = $d->toDateString();
        actDay($winner, $day, ['spend' => 300, 'purchase_value' => 900, 'purchases' => 1]);
        actDay($loser, $day, ['spend' => 100, 'purchase_value' => 10, 'purchases' => 1]);
        actDay($paused, $day, ['spend' => 100, 'purchase_value' => 10, 'purchases' => 1]);
        actDay($nothing, $day, ['spend' => 100, 'purchases' => 0]);
        actDay($tired, $day, ['spend' => 100, 'purchase_value' => 150, 'purchases' => 1, 'impressions' => 1000, 'clicks' => $day >= '2026-09-28' ? 15 : 30, 'reach' => 333]);
    }

    $out = collect(app(StopAdvisor::class)->suggest(new AdsFilter(CarbonImmutable::parse('2026-09-17'), CarbonImmutable::parse('2026-09-30'))))->keyBy('name');

    expect($out->keys()->sort()->values()->all())->toBe(['Loser', 'No sales', 'Out of stock', 'Tired'])
        ->and(array_column($out['Loser']['reasons'], 'key'))->toContain('roas_below')
        ->and(array_column($out['Tired']['reasons'], 'key'))->toContain('fatigue')
        ->and(array_column($out['No sales']['reasons'], 'key'))->toContain('no_purchases')
        ->and($out['Out of stock']['reasons'])->toBe([['key' => 'need_stop', 'params' => ['material' => 'Black abaya']]])
        ->and($out['Loser'])->toMatchArray(['ad_id' => $loser->id, 'external_id' => $loser->external_id, 'account_id' => $acc->id, 'account' => 'LV Main', 'spend' => 1400.0]);
});

it('shows suggestions and the log on the actions page, scoped to the buyer', function () {
    $mine = AdAccount::factory()->meta()->create(['name' => 'Mine']);
    $other = AdAccount::factory()->meta()->create(['name' => 'Other']);
    $bad = Ad::factory()->for($mine, 'account')->create(['name' => 'My loser']);
    $foreign = Ad::factory()->for($other, 'account')->create(['name' => 'Their loser']);
    foreach (range(1, 6) as $i) {
        $day = CarbonImmutable::now(AdsFilter::TIMEZONE)->subDays($i)->toDateString();
        actDay($bad, $day, ['spend' => 200, 'purchase_value' => 10, 'purchases' => 1]);
        actDay($foreign, $day, ['spend' => 200, 'purchase_value' => 10, 'purchases' => 1]);
    }
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    AdAction::create(['user_id' => $admin->id, 'platform' => 'meta', 'ad_account_id' => $mine->id, 'level' => 'ad', 'external_id' => 'x1', 'name' => 'Mine stopped', 'to_status' => 'PAUSED', 'result' => 'ok']);
    AdAction::create(['user_id' => $admin->id, 'platform' => 'meta', 'ad_account_id' => $other->id, 'level' => 'ad', 'external_id' => 'x2', 'name' => 'Theirs stopped', 'to_status' => 'PAUSED', 'result' => 'ok']);

    $this->actingAs(actBuyer($mine))->get('/ads/actions')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Ads/Actions')
        ->has('suggestions', 1)->where('suggestions.0.name', 'My loser')->where('suggestions.0.can_write', true)
        ->has('log', 1)->where('log.0.name', 'Mine stopped'));

    $this->actingAs($admin)->get('/ads/actions')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->has('suggestions', 2)->has('log', 2));

    $this->actingAs(User::factory()->create(['role' => UserRole::Content]))->get('/ads/actions')->assertRedirect('/ads/materials');
});

it('keeps google accounts safe: no writer, logged error', function () {
    $acc = AdAccount::factory()->google()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => $ad->external_id]))->assertStatus(422);

    expect(AdAction::first()->result)->toBe('error')->and($ad->refresh()->status)->toBe('ACTIVE');
});

it('keeps an error row when the writer throws something that is not a platform error', function () {
    $acc = AdAccount::factory()->meta()->create(['name' => 'LV Main']);
    $ad = Ad::factory()->for($acc, 'account')->create();
    $double = Mockery::mock(FakeAdsDriver::class);
    $double->shouldReceive('setStatus')->once()->andThrow(new RuntimeException('boom access_token=SECRET123'));
    app()->bind(FakeAdsDriver::class, fn () => $double);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => $ad->external_id]))
        ->assertStatus(422)->assertJsonPath('errors.status.0', __('ads.errors.failed'));

    $row = AdAction::first();
    expect($row->result)->toBe('error')->and($row->error)->not->toContain('SECRET123')->and($row->error)->toContain('boom')
        ->and($row->account_name)->toBe('LV Main')->and($ad->refresh()->status)->toBe('ACTIVE');
});

it('keeps the ok row when the local status save fails after the platform call', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    Event::listen('eloquent.saving: '.Ad::class, fn () => throw new RuntimeException('db down'));

    try {
        $this->actingAs($admin)->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => $ad->external_id]))->assertOk();
    } finally {
        Event::forget('eloquent.saving: '.Ad::class);
    }

    $row = AdAction::first();
    expect(Cache::get('ads-fake-writer')['statuses'])->toHaveCount(1)
        ->and($row->result)->toBe('ok')->and($row->error)->toContain('Local status not updated')
        ->and($ad->refresh()->status)->toBe('ACTIVE');
});

it('never lets a moderator write, at the service level and without a platform call', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $mod = User::factory()->create(['role' => UserRole::Moderator]);

    expect(app(AdWriteService::class)->canWrite($mod, $acc))->toBeFalse();
    expect(fn () => app(AdWriteService::class)->setStatus($mod, $acc, 'ad', $ad->external_id, 'paused', null))->toThrow(AuthorizationException::class);
    expect(Cache::get('ads-fake-writer'))->toBeNull()->and(AdAction::count())->toBe(0);
});

it('never refuses on a stale local status: Stop on a locally paused ad still calls the platform and logs', function () {
    $acc = AdAccount::factory()->meta()->create();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'PAUSED', 'effective_status' => 'PAUSED']);

    $this->actingAs($admin)->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => $ad->external_id, 'status' => 'paused']))->assertOk();
    expect(Cache::get('ads-fake-writer')['statuses'])->toHaveCount(1)
        ->and(AdAction::first())->result->toBe('ok')->from_status->toBe('PAUSED')->to_status->toBe('PAUSED');
});

it('uses the own status for the local update, effective status is left alone', function () {
    $acc = AdAccount::factory()->meta()->create();
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    // own PAUSED: Run works and changes only the own status
    $paused = Ad::factory()->for($acc, 'account')->create(['status' => 'PAUSED', 'effective_status' => 'PAUSED']);
    $this->actingAs($admin)->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => $paused->external_id, 'status' => 'active']))->assertOk();
    $paused->refresh();
    expect($paused->status)->toBe('ACTIVE')->and($paused->effective_status)->toBe('PAUSED');
});

it('suggests ads by their own status, TikTok ENABLE included, and stops a TikTok ad', function () {
    $tt = AdAccount::factory()->tiktok()->create(['name' => 'TT']);
    $tiktok = Ad::factory()->for($tt, 'account')->create(['name' => 'TT loser', 'status' => 'ENABLE', 'effective_status' => null]);
    $stopped = Ad::factory()->for($tt, 'account')->create(['name' => 'TT stopped', 'status' => 'DISABLE', 'effective_status' => 'ACTIVE']);
    $folded = Ad::factory()->for($tt, 'account')->create(['name' => 'Own active parent paused', 'status' => 'ENABLE', 'effective_status' => 'CAMPAIGN_PAUSED']);
    foreach (CarbonPeriod::create('2026-09-17', '2026-09-30') as $d) {
        foreach ([$tiktok, $stopped, $folded] as $a) {
            actDay($a, $d->toDateString(), ['spend' => 100, 'purchase_value' => 10, 'purchases' => 1]);
        }
    }

    $out = collect(app(StopAdvisor::class)->suggest(new AdsFilter(CarbonImmutable::parse('2026-09-17'), CarbonImmutable::parse('2026-09-30'))));

    expect($out->pluck('name')->sort()->values()->all())->toBe(['Own active parent paused', 'TT loser'])
        ->and($out->firstWhere('name', 'TT loser')['platform'])->toBe('tiktok');

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->postJson('/ads/actions/status', actPost(['account_id' => $tt->id, 'external_id' => $tiktok->external_id]))->assertOk();
    expect($tiktok->refresh()->status)->toBe('PAUSED')->and(AdAction::first()->from_status)->toBe('ENABLE');
});

it('flags ads whose campaign or ad set is paused on the creatives and campaign pages', function () {
    $acc = AdAccount::factory()->meta()->create();
    $camp = AdCampaign::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $set = AdSet::factory()->for($camp, 'campaign')->create(['status' => 'ACTIVE']);
    $ad = Ad::factory()->for($acc, 'account')->create(['ad_campaign_id' => $camp->id, 'ad_set_id' => $set->id, 'status' => 'ACTIVE', 'effective_status' => 'CAMPAIGN_PAUSED']);
    $free = Ad::factory()->for($acc, 'account')->create(['status' => 'ACTIVE', 'effective_status' => 'ACTIVE']);
    $day = CarbonImmutable::now(AdsFilter::TIMEZONE)->subDay()->toDateString();
    actDay($ad, $day);
    actDay($free, $day);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->get('/ads/creatives')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('result.data', fn ($rows) => collect($rows)->firstWhere('id', $ad->id)['parent_paused'] === true
            && collect($rows)->firstWhere('id', $free->id)['parent_paused'] === false));

    $this->actingAs($admin)->get('/ads/campaigns')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('tree', fn ($tree) => collect($tree)->flatMap(fn ($c) => $c['children'])->flatMap(fn ($s) => $s['children'])->firstWhere('id', $ad->id)['parent_paused'] === true));
});

it('marks each campaign node and creative row with can_write for its account', function () {
    $on = AdAccount::factory()->meta()->create(['name' => 'On']);
    $off = AdAccount::factory()->meta()->create(['name' => 'Off', 'is_active' => false]);
    foreach ([$on, $off] as $acc) {
        $ad = Ad::factory()->for($acc, 'account')->create(['name' => 'Ad '.$acc->name]);
        actDay($ad, CarbonImmutable::now(AdsFilter::TIMEZONE)->subDay()->toDateString(), ['spend' => 100]);
    }
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $page = $this->actingAs($admin)->get('/ads/creatives?status=all')->assertOk();
    $rows = collect($page->viewData('page')['props']['result']['data'])->keyBy('account');
    expect($rows['On']['can_write'])->toBeTrue()->and($rows['Off']['can_write'])->toBeFalse();

    $tree = collect($this->actingAs($admin)->get('/ads/campaigns')->assertOk()->viewData('page')['props']['tree']);
    $flat = [];
    $walk = function (array $nodes) use (&$walk, &$flat) {
        foreach ($nodes as $n) {
            $flat[] = [$n['account'], $n['can_write']];
            $walk($n['children']);
        }
    };
    $walk($tree->all());
    expect($flat)->not->toBeEmpty();
    foreach ($flat as [$account, $can]) {
        expect($can)->toBe($account === 'On');
    }
});

it('refuses a buyer Run or Stop above ad level with a readable 422, an error row and no platform call', function () {
    $acc = AdAccount::factory()->meta()->create();
    $camp = AdCampaign::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $set = AdSet::factory()->for($camp, 'campaign')->create(['status' => 'PAUSED']);
    $buyer = actBuyer($acc);

    $cases = [['campaign', $camp->external_id, 'active', 'campaign_level_not_allowed'], ['adset', $set->external_id, 'active', 'adset_level_not_allowed'],
        ['campaign', $camp->external_id, 'paused', 'campaign_level_not_allowed'], ['adset', $set->external_id, 'paused', 'adset_level_not_allowed']];
    foreach ($cases as $i => [$level, $id, $status, $key]) {
        $this->actingAs($buyer)->postJson('/ads/actions/status', ['account_id' => $acc->id, 'level' => $level, 'external_id' => $id, 'status' => $status, 'reason' => 'too risky'])
            ->assertStatus(422)->assertJsonValidationErrors(['status'])->assertJsonPath('errors.status.0', __('ads.errors.'.$key));
        expect(AdAction::count())->toBe($i + 1);
    }
    expect(AdAction::pluck('reason')->unique()->all())->toBe(['too risky']);
    expect(Cache::get('ads-fake-writer'))->toBeNull()
        ->and(AdAction::where('result', 'error')->where('error', 'level_not_allowed')->count())->toBe(4)
        ->and($camp->refresh()->status)->toBe('PAUSED');
});

it('lets a buyer Run and Stop at ad level; a supervisor too, but not Run on a campaign; admin Runs a campaign', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $camp = AdCampaign::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $buyer = actBuyer($acc);
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($buyer)->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => $ad->external_id, 'status' => 'active']))->assertOk();
    $this->actingAs($buyer)->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => $ad->external_id, 'status' => 'paused']))->assertOk();
    $this->actingAs($sup)->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => $ad->external_id, 'status' => 'paused']))->assertOk();
    $this->actingAs($sup)->postJson('/ads/actions/status', ['account_id' => $acc->id, 'level' => 'campaign', 'external_id' => $camp->external_id, 'status' => 'active'])->assertStatus(422);
    expect($camp->refresh()->status)->toBe('PAUSED');
    $this->actingAs($admin)->postJson('/ads/actions/status', ['account_id' => $acc->id, 'level' => 'campaign', 'external_id' => $camp->external_id, 'status' => 'active'])->assertOk();
    expect($camp->refresh()->status)->toBe('ACTIVE');
});

it('allowedLevels: admin all three, everyone else ad only', function () {
    $svc = app(AdWriteService::class);
    expect($svc->allowedLevels(User::factory()->create(['role' => UserRole::Admin])))->toBe(['campaign', 'adset', 'ad'])
        ->and($svc->allowedLevels(User::factory()->create(['role' => UserRole::Supervisor])))->toBe(['ad'])
        ->and($svc->allowedLevels(User::factory()->create(['role' => UserRole::MediaBuyer])))->toBe(['ad']);
});

it('gives a buyer can_write only on ad nodes of the campaigns tree', function () {
    $acc = AdAccount::factory()->meta()->create(['name' => 'Mine']);
    $camp = AdCampaign::factory()->for($acc, 'account')->create();
    $set = AdSet::factory()->for($camp, 'campaign')->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['ad_set_id' => $set->id]);
    actDay($ad, CarbonImmutable::now(AdsFilter::TIMEZONE)->subDay()->toDateString());
    $buyer = actBuyer($acc);

    $tree = $this->actingAs($buyer)->get('/ads/campaigns')->assertOk()->viewData('page')['props']['tree'];
    $levels = [];
    $walk = function (array $nodes) use (&$walk, &$levels) {
        foreach ($nodes as $n) {
            $levels[$n['level']][] = $n['can_write'];
            $walk($n['children']);
        }
    };
    $walk($tree);
    expect($levels['campaign'])->each->toBeFalse()->and($levels['adset'])->each->toBeFalse()->and($levels['ad'])->each->toBeTrue();
});
