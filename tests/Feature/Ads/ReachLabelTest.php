<?php

use App\Ads\Reports\AdInsights;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdDailyMetric;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

function rlAd(int $clicksRecent, int $clicksBefore): Ad
{
    $ad = Ad::factory()->for(AdAccount::factory()->create(), 'account')->create();
    foreach (CarbonPeriod::create('2026-09-17', '2026-09-30') as $d) {
        $day = $d->toDateString();
        AdDailyMetric::factory()->create([
            'ad_id' => $ad->id, 'ad_account_id' => $ad->ad_account_id, 'date' => $day, 'spend' => 100,
            'impressions' => 1000, 'clicks' => $day >= '2026-09-28' ? $clicksRecent : $clicksBefore, 'reach' => 333,
        ]);
    }

    return $ad;
}

it('never derives a frequency from summed daily reach', function () {
    $ad = rlAd(30, 30);

    $f = app(AdInsights::class)->forAds([$ad->id], CarbonImmutable::parse('2026-09-30'))[$ad->id]['fatigue'];

    expect($f['frequency'])->toBeNull()->and($f['flag'])->toBeFalse()->and($f['ctr_drop_pct'])->toBe(0.0);
});

it('flags fatigue from the CTR drop alone', function () {
    $ad = rlAd(15, 30);   // CTR 3 % -> 1.5 %, reach 333 a day would have meant frequency 3.0 before

    $f = app(AdInsights::class)->forAds([$ad->id], CarbonImmutable::parse('2026-09-30'))[$ad->id]['fatigue'];

    expect($f)->toBe(['flag' => true, 'ctr_drop_pct' => 50.0, 'frequency' => null]);
});

it('labels the reach figure as a sum of daily reach in both languages', function () {
    foreach (['en', 'ar'] as $lang) {
        $ts = file_get_contents(resource_path("js/i18n/{$lang}.ts"));
        expect($ts)->toMatch('/\breach_daily_sum:/');
    }

    $vue = file_get_contents(resource_path('js/pages/Ads/Numbers.vue'));
    expect($vue)->toContain('ads.kpi.reach_daily_sum')->and($vue)->not->toContain("t('ads.kpi.reach')");
});
