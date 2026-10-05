<?php

use App\Ads\Platforms\Meta\MetaAdsApi;
use App\Ads\Platforms\Meta\UsageRecorder;
use App\Ads\Platforms\RateLimited;
use App\Models\AdAccount;
use App\Models\AdsApiUsage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(fn () => Http::preventStrayRequests());

it('records the business use case header and resolves the account from the path', function () {
    $acc = AdAccount::factory()->meta()->create(['external_id' => 'act_777']);
    $header = '{"123":[{"type":"ads_insights","call_count":40,"total_cputime":12,"total_time":20,"estimated_time_to_regain_access":0}]}';
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => []], 200, ['x-business-use-case-usage' => $header])]);

    app(MetaAdsApi::class)->get('tok', 'act_777/insights');

    $row = AdsApiUsage::sole();
    expect($row->ad_account_id)->toBe($acc->id)
        ->and($row->business_id)->toBe('123')
        ->and($row->header)->toBe('x-business-use-case-usage')
        ->and($row->usage_type)->toBe('ads_insights')
        ->and((float) $row->max_pct)->toBe(40.0)
        ->and((float) $row->total_cputime)->toBe(12.0)
        ->and($row->recorded_at)->not->toBeNull();
});

it('records the ad account usage header', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => []], 200, ['x-ad-account-usage' => '{"acc_id_util_pct":55}'])]);

    app(MetaAdsApi::class)->get('tok', 'me');

    $row = AdsApiUsage::sole();
    expect($row->header)->toBe('x-ad-account-usage')->and((float) $row->max_pct)->toBe(55.0)->and($row->ad_account_id)->toBeNull();
});

it('ignores a malformed or missing header without failing the call', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => [1]], 200, ['x-business-use-case-usage' => '{not json'])]);

    $out = app(MetaAdsApi::class)->get('tok', 'me');

    expect($out['data'])->toBe([1])->and(AdsApiUsage::count())->toBe(0);
});

it('never breaks the API call when recording fails', function () {
    Schema::drop('ads_api_usage');
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => [1]], 200, ['x-ad-account-usage' => '{"acc_id_util_pct":10}'])]);

    expect(app(MetaAdsApi::class)->get('tok', 'me')['data'])->toBe([1]);
});

it('records usage on an error response and still throws RateLimited', function () {
    $header = '{"123":[{"type":"ads_management","call_count":99,"total_cputime":1,"total_time":1,"estimated_time_to_regain_access":12}]}';
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 17, 'message' => 'User request limit reached']], 400, ['x-business-use-case-usage' => $header])]);

    expect(fn () => app(MetaAdsApi::class)->get('tok', 'act_5/insights'))->toThrow(RateLimited::class);

    $row = AdsApiUsage::sole();
    expect((float) $row->max_pct)->toBe(99.0)->and($row->regain_minutes)->toBe(12);
});

it('latest returns the busiest recent row and ignores old ones', function () {
    $acc = AdAccount::factory()->meta()->create();
    AdsApiUsage::create(['ad_account_id' => $acc->id, 'header' => 'x-ad-account-usage', 'call_count' => 0, 'total_time' => 0, 'total_cputime' => 0, 'max_pct' => 30, 'recorded_at' => now()->subMinutes(5)]);
    AdsApiUsage::create(['ad_account_id' => $acc->id, 'header' => 'x-ad-account-usage', 'call_count' => 0, 'total_time' => 0, 'total_cputime' => 0, 'max_pct' => 70, 'regain_minutes' => 3, 'recorded_at' => now()->subMinutes(2)]);
    AdsApiUsage::create(['ad_account_id' => $acc->id, 'header' => 'x-ad-account-usage', 'call_count' => 0, 'total_time' => 0, 'total_cputime' => 0, 'max_pct' => 99, 'recorded_at' => now()->subHour()]);

    $latest = app(UsageRecorder::class)->latest($acc->id);

    expect($latest['max_pct'])->toBe(70.0)->and($latest['regain_minutes'])->toBe(3)
        ->and(app(UsageRecorder::class)->latest($acc->id + 99))->toBeNull();
});
