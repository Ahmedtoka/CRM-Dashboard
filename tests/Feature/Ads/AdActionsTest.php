<?php

use App\Ads\Control\AdWriteService;
use App\Ads\Control\StopAdvisor;
use App\Ads\Control\Write\WriteActionService;
use App\Ads\Control\Write\WriteDenied;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Ads\Platforms\MissingPermission;
use App\Ads\Platforms\RateLimited;
use App\Ads\Reports\AdInsights;
use App\Ads\Reports\AdsFilter;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdCampaign;
use App\Models\AdDailyMetric;
use App\Models\AdMaterial;
use App\Models\AdsAuditLog;
use App\Models\AdSet;
use App\Models\AdWriteAction;
use App\Models\MediaBuyer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::forget('ads-fake-writer');
    FakeAdsDriver::reset();
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

    $res = $this->actingAs($admin)->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => $ad->external_id]))
        ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('status', 'PAUSED')->assertJsonPath('message', __('ads.flash.stopped'));

    expect(Cache::get('ads-fake-writer')['statuses'])->toBe([['level' => 'ad', 'id' => $ad->external_id, 'status' => 'paused']]);
    $ad->refresh();
    expect($ad->status)->toBe('PAUSED')->and($ad->effective_status)->toBe('ACTIVE'); // effective is left to the next sync
    $row = AdWriteAction::sole();
    expect($row->public_id)->toBe($res->json('action_id'))->and($row->source)->toBe('legacy')
        ->and($row->proposed_by_id)->toBe($admin->id)->and($row->confirmed_by_id)->toBe($admin->id)->and($row->platform)->toBe('meta')
        ->and($row->ad_account_id)->toBe($acc->id)->and($row->target_level)->toBe('ad')->and($row->target_name)->toBe('Tired ad')
        ->and($row->from_status)->toBe('ACTIVE')->and($row->to_status)->toBe('paused')->and($row->reason)->toBe('ROAS too low')
        ->and($row->state)->toBe('succeeded')->and($row->error_code)->toBeNull();
});

it('runs a campaign and an ad set again and updates their local status', function () {
    $acc = AdAccount::factory()->meta()->create();
    $camp = AdCampaign::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $set = AdSet::factory()->for($camp, 'campaign')->create(['status' => 'PAUSED']);
    $admin = User::factory()->adsAuthority()->create(['role' => UserRole::Admin]);
    app(FakeAdsDriver::class)->seedObject('campaign', $camp->external_id, ['dailyBudgetMinor' => 100000]); // the Run guard needs a budget

    $this->actingAs($admin)->postJson('/ads/actions/status', ['account_id' => $acc->id, 'level' => 'campaign', 'external_id' => $camp->external_id, 'status' => 'active'])->assertOk();
    $this->actingAs($admin)->postJson('/ads/actions/status', ['account_id' => $acc->id, 'level' => 'adset', 'external_id' => $set->external_id, 'status' => 'active'])->assertOk();

    expect($camp->refresh()->status)->toBe('ACTIVE')->and($set->refresh()->status)->toBe('ACTIVE')
        ->and(AdWriteAction::orderBy('id')->pluck('to_status')->all())->toBe(['active', 'active'])
        ->and(AdWriteAction::orderBy('id')->pluck('state')->unique()->all())->toBe(['succeeded'])
        ->and(AdWriteAction::orderBy('id')->first()->from_status)->toBe('PAUSED');
});

it('lets a media buyer act on their own account only', function () {
    $mine = AdAccount::factory()->meta()->create();
    $other = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($mine, 'account')->create();
    $foreign = Ad::factory()->for($other, 'account')->create();
    $buyer = actBuyer($mine);

    $this->actingAs($buyer)->postJson('/ads/actions/status', actPost(['account_id' => $other->id, 'external_id' => $foreign->external_id]))
        ->assertForbidden()->assertJsonPath('code', 'out_of_scope');
    expect(Cache::get('ads-fake-writer'))->toBeNull()->and(AdWriteAction::count())->toBe(0)->and($foreign->refresh()->status)->toBe('ACTIVE');

    $this->actingAs($buyer)->postJson('/ads/actions/status', actPost(['account_id' => $mine->id, 'external_id' => $ad->external_id]))->assertOk();
    expect($ad->refresh()->status)->toBe('PAUSED');
});

it('refuses a buyer whose assignment ended before today', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $buyer = actBuyer($acc, '2026-02-01');

    $this->actingAs($buyer)->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => $ad->external_id]))->assertForbidden();
    expect(AdWriteAction::count())->toBe(0)->and(Cache::get('ads-fake-writer'))->toBeNull();
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

    expect(Cache::get('ads-fake-writer'))->toBeNull()->and(AdWriteAction::count())->toBe(0);
});

it('answers 422 with the readable message and records a failed action when the platform refuses', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $double = Mockery::mock(FakeAdsDriver::class)->makePartial();
    $double->shouldReceive('setStatus')->once()->andThrow(new MissingPermission('Meta permission missing: ads_management'));
    app()->bind(FakeAdsDriver::class, fn () => $double);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => $ad->external_id]))
        ->assertStatus(422)->assertJsonValidationErrors('status')->assertJsonPath('code', 'permission_missing')
        ->assertJsonPath('errors.status.0', __('ads.errors.permission_missing'))
        ->assertJsonPath('details.platform_message', 'Meta permission missing: ads_management');

    $row = AdWriteAction::sole();
    expect($row->state)->toBe('failed')->and($row->error_code)->toBe('permission_missing')
        ->and($row->error_message)->toBe('Meta permission missing: ads_management')->and($row->to_status)->toBe('paused')
        ->and($ad->refresh()->status)->toBe('ACTIVE');
});

it('maps a rate limit on a Run to a readable 429', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $double = Mockery::mock(FakeAdsDriver::class)->makePartial();
    $double->shouldReceive('setStatus')->once()->andThrow(new RateLimited('(#17) User request limit reached', 300));
    app()->bind(FakeAdsDriver::class, fn () => $double);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => $ad->external_id, 'status' => 'active']))
        ->assertStatus(429)->assertJsonPath('errors.status.0', __('ads.errors.rate_limited'))->assertHeader('Retry-After', '300');
    expect(AdWriteAction::sole()->state)->toBe('failed');
});

it('rejects an unknown target without calling the platform', function () {
    $acc = AdAccount::factory()->meta()->create();
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => 'nope']))
        ->assertNotFound()->assertJsonPath('code', 'not_found')->assertJsonPath('errors.status.0', __('ads.errors.not_found'));

    expect(Cache::get('ads-fake-writer'))->toBeNull()->and(AdWriteAction::count())->toBe(0);
});

it('refuses out of scope at the service level', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $content = User::factory()->create(['role' => UserRole::Content]);

    expect(app(AdWriteService::class)->canWrite($content, $acc))->toBeFalse();
    expect(fn () => app(WriteActionService::class)->propose($content, $acc, 'ad', $ad->external_id, 'paused', null, 'svc-key-0001'))
        ->toThrow(fn (WriteDenied $e) => expect($e->errorCode)->toBe('out_of_scope'));
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
        ->and(array_column($out['Tired']['reasons'], 'params', 'key')['fatigue'])->toBe(['ctr_drop' => 50.0])
        ->and(app(AdInsights::class)->forAds([$tired->id], CarbonImmutable::parse('2026-09-30'))[$tired->id]['fatigue']['frequency'])->toBeNull()
        ->and(array_column($out['No sales']['reasons'], 'key'))->toContain('no_purchases')
        ->and($out['Out of stock']['reasons'])->toBe([['key' => 'need_stop', 'params' => ['material' => 'Black abaya']]])
        ->and($out['Loser'])->toMatchArray(['ad_id' => $loser->id, 'external_id' => $loser->external_id, 'account_id' => $acc->id, 'account' => 'LV Main', 'spend' => 1400.0]);
});

it('shows suggestions and the log on the actions page, scoped to the buyer', function () {
    $mine = AdAccount::factory()->meta()->create(['name' => 'Mine']);
    $other = AdAccount::factory()->meta()->create(['name' => 'Other']);
    $bad = Ad::factory()->for($mine, 'account')->create(['name' => 'My loser', 'ad_campaign_id' => activeCampaignId($mine)]);
    $foreign = Ad::factory()->for($other, 'account')->create(['name' => 'Their loser', 'ad_campaign_id' => activeCampaignId($other)]);
    foreach (range(1, 6) as $i) {
        $day = CarbonImmutable::now(AdsFilter::TIMEZONE)->subDays($i)->toDateString();
        actDay($bad, $day, ['spend' => 200, 'purchase_value' => 10, 'purchases' => 1]);
        actDay($foreign, $day, ['spend' => 200, 'purchase_value' => 10, 'purchases' => 1]);
    }
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    // ad_actions is frozen (history only): rows are seeded raw, as the slice-1 endpoint left them.
    DB::table('ad_actions')->insert(['user_id' => $admin->id, 'platform' => 'meta', 'ad_account_id' => $mine->id, 'level' => 'ad', 'external_id' => 'x1', 'name' => 'Mine stopped', 'to_status' => 'PAUSED', 'result' => 'ok', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('ad_actions')->insert(['user_id' => $admin->id, 'platform' => 'meta', 'ad_account_id' => $other->id, 'level' => 'ad', 'external_id' => 'x2', 'name' => 'Theirs stopped', 'to_status' => 'PAUSED', 'result' => 'ok', 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs(actBuyer($mine))->get('/ads/actions')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Ads/Actions')
        ->has('suggestions', 1)->where('suggestions.0.name', 'My loser')->where('suggestions.0.can_write', true)
        ->has('log', 1)->where('log.0.name', 'Mine stopped'));

    $this->actingAs($admin)->get('/ads/actions')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->has('suggestions', 2)->has('log', 2));

    $this->actingAs(User::factory()->create(['role' => UserRole::Content]))->get('/ads/actions')->assertRedirect('/ads/materials');
});

it('keeps google accounts safe: no writer, refused and audited', function () {
    $acc = AdAccount::factory()->google()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => $ad->external_id]))
        ->assertStatus(422)->assertJsonPath('code', 'platform_not_writable');

    expect(AdWriteAction::count())->toBe(0)->and(AdsAuditLog::where('action', 'write.refused')->sole()->meta['code'])->toBe('platform_not_writable')
        ->and($ad->refresh()->status)->toBe('ACTIVE');
});

it('keeps a failed action when the writer throws something that is not a platform error, without the secret', function () {
    $acc = AdAccount::factory()->meta()->create(['name' => 'LV Main']);
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $double = Mockery::mock(FakeAdsDriver::class)->makePartial();
    $double->shouldReceive('setStatus')->once()->andThrow(new RuntimeException('boom access_token=SECRET123'));
    app()->bind(FakeAdsDriver::class, fn () => $double);

    // A Run: the read-back finds it still PAUSED, so the outcome is definite (not applied).
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => $ad->external_id, 'status' => 'active']))
        ->assertStatus(422)->assertJsonPath('code', 'not_applied')->assertJsonPath('errors.status.0', __('ads.errors.not_applied'));

    $row = AdWriteAction::sole();
    expect($row->state)->toBe('failed')->and($row->error_message)->not->toContain('SECRET123')->and($row->error_message)->toContain('boom')
        ->and($row->account_name)->toBe('LV Main')->and($ad->refresh()->status)->toBe('PAUSED');
});

it('keeps the succeeded action when the local status save fails after the platform call', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    Event::listen('eloquent.saving: '.Ad::class, fn () => throw new RuntimeException('db down'));

    try {
        $this->actingAs($admin)->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => $ad->external_id]))->assertOk();
    } finally {
        Event::forget('eloquent.saving: '.Ad::class);
    }

    $row = AdWriteAction::sole();
    expect(Cache::get('ads-fake-writer')['statuses'])->toHaveCount(1)
        ->and($row->state)->toBe('succeeded')->and($row->outcome['local_status_error'])->toContain('db down')
        ->and($ad->refresh()->status)->toBe('ACTIVE');
});

it('never lets a moderator write, at the service level and without a platform call', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $mod = User::factory()->create(['role' => UserRole::Moderator]);

    expect(app(AdWriteService::class)->canWrite($mod, $acc))->toBeFalse();
    expect(fn () => app(WriteActionService::class)->propose($mod, $acc, 'ad', $ad->external_id, 'paused', null, 'svc-key-0002'))->toThrow(WriteDenied::class);
    expect(Cache::get('ads-fake-writer'))->toBeNull()->and(AdWriteAction::count())->toBe(0);
});

it('never refuses on a stale local status: Stop on a locally paused ad still calls the platform and logs', function () {
    $acc = AdAccount::factory()->meta()->create();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'PAUSED', 'effective_status' => 'PAUSED']);

    $this->actingAs($admin)->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => $ad->external_id, 'status' => 'paused']))->assertOk();
    expect(Cache::get('ads-fake-writer')['statuses'])->toHaveCount(1)
        ->and(AdWriteAction::sole())->state->toBe('succeeded')->from_status->toBe('PAUSED')->to_status->toBe('paused');
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
    expect($tiktok->refresh()->status)->toBe('PAUSED')->and(AdWriteAction::sole()->from_status)->toBe('ENABLE');
});

it('flags ads whose campaign or ad set is paused on the creatives and campaign pages', function () {
    $acc = AdAccount::factory()->meta()->create();
    // D1: an ad of a paused campaign is not listed at all, so the paused parent here is the ad set
    $camp = AdCampaign::factory()->for($acc, 'account')->create(['status' => 'ACTIVE']);
    $set = AdSet::factory()->for($camp, 'campaign')->create(['status' => 'PAUSED']);
    $ad = Ad::factory()->for($acc, 'account')->create(['ad_campaign_id' => $camp->id, 'ad_set_id' => $set->id, 'status' => 'ACTIVE', 'effective_status' => 'ADSET_PAUSED']);
    $free = Ad::factory()->for($acc, 'account')->create(['status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'ad_campaign_id' => activeCampaignId($acc)]);
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
        $ad = Ad::factory()->for($acc, 'account')->create(['name' => 'Ad '.$acc->name, 'ad_campaign_id' => activeCampaignId($acc)]);
        actDay($ad, CarbonImmutable::now(AdsFilter::TIMEZONE)->subDay()->toDateString(), ['spend' => 100]);
    }
    $admin = User::factory()->adsAuthority()->create(['role' => UserRole::Admin]);

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

it('refuses a buyer Run or Stop above ad level with a readable 403, an audit row and no platform call', function () {
    $acc = AdAccount::factory()->meta()->create();
    $camp = AdCampaign::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $set = AdSet::factory()->for($camp, 'campaign')->create(['status' => 'PAUSED']);
    $buyer = actBuyer($acc);

    $cases = [['campaign', $camp->external_id, 'active'], ['adset', $set->external_id, 'active'], ['campaign', $camp->external_id, 'paused'], ['adset', $set->external_id, 'paused']];
    foreach ($cases as $i => [$level, $id, $status]) {
        $this->actingAs($buyer)->postJson('/ads/actions/status', ['account_id' => $acc->id, 'level' => $level, 'external_id' => $id, 'status' => $status, 'reason' => 'too risky'])
            ->assertForbidden()->assertJsonPath('code', 'ads_authority_required')->assertJsonPath('details.level', $level)
            ->assertJsonPath('errors.status.0', __('ads.errors.ads_authority_required'));
        expect(AdsAuditLog::where('action', 'write.refused')->count())->toBe($i + 1);
    }
    expect(AdsAuditLog::where('action', 'write.refused')->get()->every(fn ($r) => $r->meta['code'] === 'ads_authority_required' && $r->meta['source'] === 'legacy'))->toBeTrue()
        ->and(AdWriteAction::count())->toBe(0)->and(Cache::get('ads-fake-writer'))->toBeNull()
        ->and($camp->refresh()->status)->toBe('PAUSED');
});

it('lets a buyer Run and Stop at ad level; a supervisor too, but not Run on a campaign; an Ads-authority admin Runs a campaign', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $camp = AdCampaign::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $buyer = actBuyer($acc);
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $admin = User::factory()->adsAuthority()->create(['role' => UserRole::Admin]);

    $this->actingAs($buyer)->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => $ad->external_id, 'status' => 'active']))->assertOk();
    $this->actingAs($buyer)->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => $ad->external_id, 'status' => 'paused']))->assertOk();
    $this->actingAs($sup)->postJson('/ads/actions/status', actPost(['account_id' => $acc->id, 'external_id' => $ad->external_id, 'status' => 'paused']))->assertOk();
    $this->actingAs($sup)->postJson('/ads/actions/status', ['account_id' => $acc->id, 'level' => 'campaign', 'external_id' => $camp->external_id, 'status' => 'active'])
        ->assertForbidden()->assertJsonPath('code', 'ads_authority_required');
    expect($camp->refresh()->status)->toBe('PAUSED');
    app(FakeAdsDriver::class)->seedObject('campaign', $camp->external_id, ['dailyBudgetMinor' => 100000]);
    $this->actingAs($admin)->postJson('/ads/actions/status', ['account_id' => $acc->id, 'level' => 'campaign', 'external_id' => $camp->external_id, 'status' => 'active'])->assertOk();
    expect($camp->refresh()->status)->toBe('ACTIVE');
});

it('allowedLevels: an Ads-authority holder all three, everyone else (an admin without the flag too) ad only', function () {
    $svc = app(AdWriteService::class);
    expect($svc->allowedLevels(User::factory()->adsAuthority()->create(['role' => UserRole::Admin])))->toBe(['campaign', 'adset', 'ad'])
        ->and($svc->allowedLevels(User::factory()->create(['role' => UserRole::Admin])))->toBe(['ad'])
        ->and($svc->allowedLevels(User::factory()->create(['role' => UserRole::Supervisor])))->toBe(['ad'])
        ->and($svc->allowedLevels(User::factory()->create(['role' => UserRole::MediaBuyer])))->toBe(['ad']);
});

it('gives a buyer can_write only on ad nodes of the campaigns tree', function () {
    $acc = AdAccount::factory()->meta()->create(['name' => 'Mine']);
    $camp = AdCampaign::factory()->for($acc, 'account')->create();
    $set = AdSet::factory()->for($camp, 'campaign')->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['ad_set_id' => $set->id, 'ad_campaign_id' => $camp->id]);
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

it('checks the account scope before the level it does not allow', function () {
    $acc = AdAccount::factory()->meta()->create();
    $content = User::factory()->create(['role' => UserRole::Content]);

    expect(fn () => app(WriteActionService::class)->propose($content, $acc, 'campaign', 'c1', 'paused', null, 'svc-key-0003'))
        ->toThrow(fn (WriteDenied $e) => expect($e->errorCode)->toBe('out_of_scope'));
    expect(AdWriteAction::count())->toBe(0);
});
