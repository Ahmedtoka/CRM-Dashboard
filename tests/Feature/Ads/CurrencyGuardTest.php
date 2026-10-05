<?php

use App\Ads\Health\DataHealth;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsOverview;
use App\Ads\Reports\RevenueSummary;
use App\Ads\Reports\TopAccounts;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdDailyMetric;
use App\Models\AdPlatformConnection;
use App\Models\AdsSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

function cgFilter(): AdsFilter
{
    return new AdsFilter(CarbonImmutable::parse('2026-09-10'), CarbonImmutable::parse('2026-09-11'));
}

function cgAccount(string $name, string $currency, float $spend, string $tz = 'Africa/Cairo'): AdAccount
{
    $c = AdPlatformConnection::factory()->meta()->create(['status' => 'connected']);
    $a = AdAccount::factory()->meta()->create(['connection_id' => $c->id, 'name' => $name, 'currency' => $currency, 'timezone' => $tz]);
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
        ->and($o['totals']['impressions'])->toBe(2000);

    $rows = collect(app(TopAccounts::class)->build(cgFilter()))->keyBy('name');
    expect($rows['Cairo Shop']['currency'])->toBe('EGP')->and($rows['Cairo Shop']['spend'])->toBe(100.0)
        ->and($rows['Dollar Shop']['currency'])->toBe('USD')->and($rows['Dollar Shop']['spend'])->toBe(50.0);

    $s = app(RevenueSummary::class)->build(cgFilter(), false);
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

    expect($r)->toHaveCount(1)->and($r[0]['reason'])->toBe('timezone')->and($r[0]['accounts'])->toBe(['Dubai Shop']);
});
