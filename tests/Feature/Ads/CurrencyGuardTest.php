<?php

use App\Ads\Buyers\AssignmentService;
use App\Ads\Health\DataHealth;
use App\Ads\Materials\MaterialPerformance;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsOverview;
use App\Ads\Reports\BuyerScorecard;
use App\Ads\Reports\RevenueSummary;
use App\Ads\Reports\TopAccounts;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountDaily;
use App\Models\AdCampaign;
use App\Models\AdDailyMetric;
use App\Models\AdMaterial;
use App\Models\AdPlatformConnection;
use App\Models\AdsSyncRun;
use App\Models\MediaBuyer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

function cgFilter(): AdsFilter
{
    return new AdsFilter(CarbonImmutable::parse('2026-09-10'), CarbonImmutable::parse('2026-09-11'));
}

function cgAccount(string $name, string $currency, float $spend, string $tz = 'Africa/Cairo'): AdAccount
{
    $c = AdPlatformConnection::factory()->meta()->create(['status' => 'connected']);
    $a = AdAccount::factory()->meta()->create(['connection_id' => $c->id, 'name' => $name, 'currency' => $currency, 'timezone' => $tz, 'complete_from' => '2026-01-01']);
    AdsSyncRun::factory()->create(['ad_account_id' => $a->id, 'status' => 'ok', 'started_at' => now()->subMinutes(11), 'finished_at' => now()->subMinutes(10)]);
    $ad = Ad::factory()->for($a, 'account')->create();
    AdDailyMetric::factory()->create(['ad_id' => $ad->id, 'ad_account_id' => $a->id, 'date' => '2026-09-10', 'spend' => $spend, 'purchase_value' => $spend * 2, 'purchases' => 1, 'impressions' => 1000, 'clicks' => 10, 'reach' => 800]);

    return $a;
}

it('never sums EGP and USD: currency is mixed and the money figures are null', function () {
    $egp = cgAccount('Cairo Shop', 'EGP', 100);
    $usd = cgAccount('Dollar Shop', 'USD', 50);

    $o = app(AdsOverview::class)->build(cgFilter());

    expect($o['currency'])->toBe('mixed')
        ->and($o['totals']['mixed_currencies'])->toBeTrue()
        ->and($o['totals']['spend'])->toBeNull()->and($o['totals']['spend_tax'])->toBeNull()
        ->and($o['totals']['purchase_value'])->toBeNull()->and($o['totals']['roas'])->toBeNull()
        ->and($o['totals']['real_revenue'])->toBeNull()
        ->and($o['totals']['impressions'])->toBe(2000)
        ->and($o['daily'])->toBe([])->and($o['platforms'])->toBe([]);

    $rows = collect(app(TopAccounts::class)->build(cgFilter()))->keyBy('name');
    expect($rows['Cairo Shop']['currency'])->toBe('EGP')->and($rows['Cairo Shop']['spend'])->toBe(100.0)
        ->and($rows['Dollar Shop']['currency'])->toBe('USD')->and($rows['Dollar Shop']['spend'])->toBe(50.0);

    $s = app(RevenueSummary::class)->build(cgFilter(), true);
    expect($s['store']['revenue'])->toBeNull();
    expect($s['currency'])->toBe('mixed')->and($s['spend'])->toBeNull()->and($s['platform']['revenue'])->toBeNull()
        ->and($s['roas']['platform'])->toBeNull()->and($s['mixed_currencies'])->toBeTrue();

    // choosing one account brings the figures back
    $one = new AdsFilter(CarbonImmutable::parse('2026-09-10'), CarbonImmutable::parse('2026-09-11'), accountIds: [$usd->id]);
    $o = app(AdsOverview::class)->build($one);
    expect($o['currency'])->toBe('USD')->and($o['totals']['spend'])->toBe(50.0)->and($o['totals']['mixed_currencies'])->toBeFalse();
});

it('leaves an EGP-only filter unchanged', function () {
    cgAccount('Cairo Shop', 'EGP', 100);
    cgAccount('Second Shop', 'EGP', 40);

    $o = app(AdsOverview::class)->build(cgFilter());

    expect($o['currency'])->toBe('EGP')->and($o['totals']['mixed_currencies'])->toBeFalse()->and($o['totals']['spend'])->toBe(140.0);
});

it('flags an in-scope account whose timezone is not Cairo', function () {
    Cache::flush();
    cgAccount('Cairo Shop', 'EGP', 100);
    cgAccount('Dubai Shop', 'EGP', 100, 'Asia/Dubai');

    $r = app(DataHealth::class)->forFilter(cgFilter())['reasons'];

    expect($r)->toHaveCount(1)->and($r[0]['reason'])->toBe('timezone')->and($r[0]['accounts'])->toBe(['Dubai Shop: Asia/Dubai']);
});

it('does not let a dormant USD account blank the dashboard', function () {
    cgAccount('Cairo Shop', 'EGP', 100);
    AdAccount::factory()->meta()->create(['name' => 'Dormant Dollar', 'currency' => 'USD']);

    $o = app(AdsOverview::class)->build(cgFilter());

    expect($o['currency'])->toBe('EGP')->and($o['totals']['mixed_currencies'])->toBeFalse()->and($o['totals']['spend'])->toBe(100.0);
});

it('counts an account that has only control rows in the range', function () {
    $egp = cgAccount('Cairo Shop', 'EGP', 100);
    $usd = AdAccount::factory()->meta()->create(['name' => 'Control Dollar', 'currency' => 'USD']);
    AdAccountDaily::create(['ad_account_id' => $usd->id, 'date' => '2026-09-10', 'spend' => 5, 'purchase_value' => 0, 'purchases' => 0, 'impressions' => 0, 'fetched_at' => now()]);

    expect(app(AdsOverview::class)->build(cgFilter())['currency'])->toBe('mixed');
});

it('follows the admin buyer filter when deciding mixed', function () {
    $buyer = MediaBuyer::factory()->create();
    $egp = cgAccount('Cairo Shop', 'EGP', 100);
    cgAccount('Dollar Shop', 'USD', 50);
    app(AssignmentService::class)->assign($egp, $buyer, CarbonImmutable::parse('2026-09-01'));

    $f = new AdsFilter(CarbonImmutable::parse('2026-09-10'), CarbonImmutable::parse('2026-09-11'), buyerId: $buyer->id);

    expect(app(AdsOverview::class)->build($f)['currency'])->toBe('EGP');
});

it('nulls a buyer card whose accounts mix currencies', function () {
    $buyer = MediaBuyer::factory()->create();
    $svc = app(AssignmentService::class);
    $svc->assign(cgAccount('Cairo Shop', 'EGP', 100), $buyer, CarbonImmutable::parse('2026-09-01'));
    $svc->assign(cgAccount('Dollar Shop', 'USD', 50), $buyer, CarbonImmutable::parse('2026-09-01'));

    $card = collect(app(BuyerScorecard::class)->build(cgFilter()))->firstWhere('buyer_id', $buyer->id);

    expect($card['mixed_currencies'])->toBeTrue()->and($card['spend'])->toBeNull()->and($card['spend_tax'])->toBeNull()
        ->and($card['purchase_value'])->toBeNull()->and($card['roas'])->toBeNull()->and($card['real_revenue'])->toBeNull()
        ->and($card['purchases'])->toBe(2.0);
    expect(app(BuyerScorecard::class)->detail($buyer, cgFilter())['daily'])->toBe([]);
});

it('keeps a single-currency buyer card as it was', function () {
    $buyer = MediaBuyer::factory()->create();
    app(AssignmentService::class)->assign(cgAccount('Cairo Shop', 'EGP', 100), $buyer, CarbonImmutable::parse('2026-09-01'));

    $card = collect(app(BuyerScorecard::class)->build(cgFilter()))->firstWhere('buyer_id', $buyer->id);

    expect($card['mixed_currencies'])->toBeFalse()->and($card['spend'])->toBe(100.0);
});

it('nulls the totals of a material whose ads run in several currencies', function () {
    $egp = cgAccount('Cairo Shop', 'EGP', 0);
    $usd = cgAccount('Dollar Shop', 'USD', 0);
    $day = CarbonImmutable::now(AdsFilter::TIMEZONE)->subDay()->toDateString();
    $live = fn (AdAccount $a) => Ad::factory()->for($a, 'account')->create(['ad_campaign_id' => AdCampaign::factory()->create(['ad_account_id' => $a->id, 'status' => 'ACTIVE'])->id]);
    $adE = $live($egp);
    $adU = $live($usd);
    $adO = $live($egp);
    foreach ([[$adE, $egp], [$adU, $usd], [$adO, $egp]] as [$ad, $acc]) {
        AdDailyMetric::factory()->create(['ad_id' => $ad->id, 'ad_account_id' => $acc->id, 'date' => $day, 'spend' => 10, 'purchase_value' => 20, 'purchases' => 1]);
    }
    $mixed = AdMaterial::factory()->create();
    $mixed->ads()->attach([$adE->id, $adU->id]);
    $single = AdMaterial::factory()->create();
    $single->ads()->attach([$adO->id]);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $perf = app(MaterialPerformance::class)->forMaterials(collect([$mixed->load('ads'), $single->load('ads')]), $admin);

    expect($perf[$mixed->id]['mixed_currencies'])->toBeTrue()->and($perf[$mixed->id]['spend'])->toBeNull()->and($perf[$mixed->id]['roas'])->toBeNull()
        ->and($perf[$single->id]['mixed_currencies'])->toBeFalse()->and($perf[$single->id]['spend'])->toBe(10.0);
});

it('hides the ROAS against EGP orders when the one currency in scope is not EGP', function () {
    $usd = cgAccount('Dollar Shop', 'USD', 50);

    $o = app(AdsOverview::class)->build(cgFilter());
    $s = app(RevenueSummary::class)->build(cgFilter(), true);

    expect($o['currency'])->toBe('USD')->and($o['totals']['real_roas'])->toBeNull()->and($o['totals']['spend'])->toBe(50.0)
        ->and($s['note'])->toBe('foreign_currency')->and($s['roas']['store'])->toBeNull()->and($s['roas']['crm'])->toBeNull()
        ->and($s['gaps']['platform_vs_crm'])->toBeNull()->and($s['gaps']['platform_vs_crm_pct'])->toBeNull()
        ->and($s['gaps']['crm_vs_store'])->not->toBeNull()
        ->and($s['roas']['platform'])->toBe(2.0);
});

it('prints no amount for the shared mixed currency and carries the new strings in both languages', function () {
    $ads = file_get_contents(resource_path('js/lib/ads.ts'));
    expect($ads)->toContain("currency === 'mixed'");

    foreach (['en', 'ar'] as $lang) {
        $ts = file_get_contents(resource_path("js/i18n/{$lang}.ts"));
        expect($ts)->toContain('foreign_currency_note:')->and($ts)->toMatch('/\bunverified: [^\n]*\{names\}/')->and($ts)->toContain('mixed_currencies:');
    }
});
