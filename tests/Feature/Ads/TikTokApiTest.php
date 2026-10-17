<?php

use App\Ads\Platforms\RateLimited;
use App\Ads\Platforms\TikTok\TikTokApi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

const TTAPI = 'business-api.tiktok.com/open_api/v1.3/';

function ttRateError(callable $call): RateLimited
{
    try {
        $call();
    } catch (RateLimited $e) {
        return $e;
    }
    throw new RuntimeException('no RateLimited thrown');
}

it('asks for the documented 5-minute wait after a 40100 throttle', function () {
    Http::preventStrayRequests();
    Http::fake([TTAPI.'*' => Http::response(['code' => 40100, 'message' => 'Too many requests. Please retry in some time.', 'data' => []])]);

    $e = ttRateError(fn () => app(TikTokApi::class)->get('tok', 'ad/get/', []));

    expect($e->retryAfterSeconds)->toBe(300);
});

it('waits until 00:00 UTC when the daily quota is used up', function () {
    Carbon::setTestNow('2026-10-17 22:30:00');
    Http::preventStrayRequests();
    Http::fake([TTAPI.'*' => Http::response(['code' => 40100, 'message' => 'The daily request limit (QPD) has been reached.', 'data' => []])]);

    $e = ttRateError(fn () => app(TikTokApi::class)->get('tok', 'ad/get/', []));

    expect($e->retryAfterSeconds)->toBe(90 * 60);
});

it('treats an HTTP 429 as a 5-minute throttle', function () {
    Http::preventStrayRequests();
    Http::fake([TTAPI.'*' => Http::response('', 429)]);

    expect(ttRateError(fn () => app(TikTokApi::class)->post('tok', 'ad/status/update/', []))->retryAfterSeconds)->toBe(300);
});

it('pages with a caller page size', function () {
    Http::preventStrayRequests();
    Http::fake([TTAPI.'comment/list/*' => Http::sequence()
        ->push(['code' => 0, 'data' => ['list' => [['comment_id' => '1']], 'page_info' => ['total_page' => 2]]])
        ->push(['code' => 0, 'data' => ['list' => [['comment_id' => '2']], 'page_info' => ['total_page' => 2]]])]);

    $rows = app(TikTokApi::class)->paginate('tok', 'comment/list/', ['advertiser_id' => '7001'], 100);

    expect(array_column($rows, 'comment_id'))->toBe(['1', '2']);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'page_size=100') && str_contains($r->url(), 'page=2'));
});

it('records a shared throttle so background polling stays off the quota', function () {
    Http::preventStrayRequests();
    Http::fake([TTAPI.'*' => Http::response(['code' => 40100, 'message' => 'Too many requests', 'data' => []])]);

    ttRateError(fn () => app(TikTokApi::class)->get('tok', 'ad/get/', []));

    expect(TikTokApi::throttledUntil())->toBe(now()->getTimestamp() + 300);
    $this->travel(301)->seconds();
    expect(TikTokApi::throttledUntil())->toBeNull();
});
