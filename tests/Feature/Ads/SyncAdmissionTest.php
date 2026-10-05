<?php

use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Ads\Platforms\Meta\MetaAdsApi;
use App\Ads\Platforms\RateLimited;
use App\Ads\Sync\AdsSyncService;
use App\Ads\Sync\SyncAdAccount;
use App\Models\AdAccount;
use App\Models\AdCampaign;
use App\Models\AdPlatformConnection;
use App\Models\AdsApiUsage;
use App\Models\AdsSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Africa/Cairo'));
    Http::preventStrayRequests();
    config(['crm.ads.drivers.meta' => 'live', 'crm.ads.history_start' => '2026-09-01']);
});

function admissionAccount(string $ext = 'act_11'): AdAccount
{
    $c = AdPlatformConnection::factory()->meta()->create(['credentials' => ['access_token' => 'tok']]);

    return AdAccount::factory()->meta()->create(['external_id' => $ext, 'connection_id' => $c->id]);
}

function admissionUsage(?AdAccount $a, float $pct, int $minutesAgo, int $regain = 0): void
{
    AdsApiUsage::create([
        'ad_account_id' => $a?->id, 'header' => 'x-business-use-case-usage', 'call_count' => $pct, 'total_time' => 0,
        'total_cputime' => 0, 'max_pct' => $pct, 'regain_minutes' => $regain, 'recorded_at' => now()->subMinutes($minutesAgo),
    ]);
}

function admissionFakeMeta(): void
{
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);
}

/** A queued (not inline) job, so a RateLimited is turned into a release. */
function admissionQueuedJob(SyncAdAccount $job, ?int &$releasedWith = null): SyncAdAccount
{
    $queueJob = Mockery::mock(Job::class)->shouldIgnoreMissing();
    $queueJob->shouldReceive('release')->andReturnUsing(function ($delay) use (&$releasedWith) {
        $releasedWith = $delay;
    });
    $job->setJob($queueJob);

    return $job;
}

it('defers a sync before any Meta request while the recent usage is above the admission level', function () {
    $acc = admissionAccount();
    admissionUsage($acc, 80, 5);
    admissionFakeMeta();

    $released = null;
    admissionQueuedJob(new SyncAdAccount($acc->id, 3), $released)->handle(app(AdsSyncService::class));

    Http::assertSentCount(0);
    $run = AdsSyncRun::sole();
    expect($released)->toBeGreaterThanOrEqual(300)
        ->and($run->status)->toBe('error')
        ->and($run->error)->toContain('deferred by Meta quota');
});

it('uses the regain time of the busiest reading as the retry delay', function () {
    $acc = admissionAccount();
    admissionUsage($acc, 92, 3, regain: 20);
    admissionFakeMeta();

    try {
        app(MetaAdsApi::class)->get('tok', 'act_11/insights');
        $this->fail('expected RateLimited');
    } catch (RateLimited $e) {
        expect($e->retryAfterSeconds)->toBe(1200)->and($e->getMessage())->toContain('deferred by Meta quota');
    }
    Http::assertSentCount(0);
});

it('sends the request when the busy reading is older than 15 minutes', function () {
    $acc = admissionAccount();
    admissionUsage($acc, 80, 20);
    admissionFakeMeta();

    app(MetaAdsApi::class)->get('tok', 'act_11/insights');

    Http::assertSentCount(1);
});

it('sends the request when admission is disabled', function () {
    config(['crm.ads.sync.admission_enabled' => false]);
    $acc = admissionAccount();
    admissionUsage($acc, 95, 2);
    admissionFakeMeta();

    app(MetaAdsApi::class)->get('tok', 'act_11/insights');

    Http::assertSentCount(1);
});

it('only looks at the usage of the account being read', function () {
    $acc = admissionAccount();
    $other = AdAccount::factory()->meta()->create(['external_id' => 'act_22']);
    admissionUsage($other, 95, 2);
    admissionFakeMeta();

    app(MetaAdsApi::class)->get('tok', 'act_11/insights');

    Http::assertSentCount(1);
});

it('never blocks a write', function () {
    $acc = admissionAccount();
    admissionUsage($acc, 99, 1);
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1'])]);

    app(MetaAdsApi::class)->post('tok', 'act_11/ads', ['name' => 'x']);

    Http::assertSentCount(1);
});

it('carries Meta estimated_time_to_regain_access on a 2xx read above the limit', function () {
    admissionAccount();
    $header = '{"11":[{"type":"ads_insights","call_count":90,"total_cputime":1,"total_time":1,"estimated_time_to_regain_access":12}]}';
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => []], 200, ['x-business-use-case-usage' => $header])]);

    try {
        app(MetaAdsApi::class)->get('tok', 'act_11/insights');
        $this->fail('expected RateLimited');
    } catch (RateLimited $e) {
        expect($e->retryAfterSeconds)->toBe(720);
    }
});

it('carries the regain time on a rate-limit error response too', function () {
    $header = '{"11":[{"type":"ads_insights","call_count":100,"total_cputime":1,"total_time":1,"estimated_time_to_regain_access":7}]}';
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 80004, 'message' => 'too many calls']], 400, ['x-business-use-case-usage' => $header])]);

    try {
        app(MetaAdsApi::class)->get('tok', 'act_11/insights');
        $this->fail('expected RateLimited');
    } catch (RateLimited $e) {
        expect($e->retryAfterSeconds)->toBe(420);
    }
});

it('releases a queued job with Meta retry-after, 900 s when Meta gave none', function () {
    $acc = admissionAccount();
    $header = '{"11":[{"type":"ads_insights","call_count":90,"total_cputime":1,"total_time":1,"estimated_time_to_regain_access":12}]}';
    $withRegain = true;
    Http::fake(function () use (&$withRegain, $header) {
        return $withRegain
            ? Http::response(['data' => []], 200, ['x-business-use-case-usage' => $header])
            : Http::response(['error' => ['code' => 17, 'message' => 'limit']], 400);
    });
    $released = null;
    admissionQueuedJob(new SyncAdAccount($acc->id, 3), $released)->handle(app(AdsSyncService::class));
    expect($released)->toBe(720);

    AdsApiUsage::query()->delete();
    $withRegain = false;
    $released = null;
    admissionQueuedJob(new SyncAdAccount($acc->id, 3), $released)->handle(app(AdsSyncService::class));
    expect($released)->toBe(900);
});

it('dispatches one hourly sync per account while one is pending, even after the cache is lost', function () {
    Queue::fake();
    $a = AdAccount::factory()->meta()->create(['external_id' => 'act_1']);
    $b = AdAccount::factory()->meta()->create(['external_id' => 'act_2']);

    $this->artisan('ads:sync', ['--days' => 3])->assertSuccessful();
    Cache::flush(); // the unique-job lock is gone; the DB marker still holds
    $this->artisan('ads:sync', ['--days' => 3])->assertSuccessful();

    Queue::assertPushed(SyncAdAccount::class, 2);
    expect($a->fresh()->sync_pending_since)->not->toBeNull()->and($b->fresh()->sync_pending_since)->not->toBeNull();
});

it('dispatches again once the pending marker is older than 8 hours', function () {
    Queue::fake();
    $a = AdAccount::factory()->meta()->create(['external_id' => 'act_1']);
    DB::table('ad_accounts')->where('id', $a->id)->update(['sync_pending_since' => now()->subHours(9)]);

    $this->artisan('ads:sync', ['--days' => 3])->assertSuccessful();

    Queue::assertPushed(SyncAdAccount::class, 1);
});

it('queues the hourly sync without the ad list and the nightly deep sync with it', function () {
    Queue::fake();
    admissionFakeMeta(); // the nightly run also rediscovers accounts
    AdAccount::factory()->meta()->create(['external_id' => 'act_1']);

    $this->artisan('ads:sync', ['--days' => 3])->assertSuccessful();
    $this->artisan('ads:sync', ['--days' => 30])->assertSuccessful();

    Queue::assertPushed(SyncAdAccount::class, fn (SyncAdAccount $j) => $j->days === 3 && $j->withAds === false);
    Queue::assertPushed(SyncAdAccount::class, fn (SyncAdAccount $j) => $j->days === 30 && $j->withAds === true);
});

it('the hourly job reads metrics but not the ad list; the 30-day job reads both', function () {
    config(['crm.ads.drivers.meta' => 'fake']);
    $acc = AdAccount::factory()->meta()->create();
    $driver = new class extends FakeAdsDriver
    {
        public int $adsCalls = 0;

        public int $metricCalls = 0;

        public function ads(AdAccount $a): array
        {
            $this->adsCalls++;

            return [];
        }

        public function dailyMetrics(AdAccount $a, CarbonImmutable $from, CarbonImmutable $to): array
        {
            $this->metricCalls++;

            return [];
        }

        public function accountDaily(AdAccount $a, CarbonImmutable $from, CarbonImmutable $to): ?array
        {
            return null; // the fake control reuses dailyMetrics; keep the count about the ad-level read
        }
    };
    app()->bind(FakeAdsDriver::class, fn () => $driver);

    (new SyncAdAccount($acc->id, 3, withAds: false))->handle(app(AdsSyncService::class));
    expect($driver->adsCalls)->toBe(0)->and($driver->metricCalls)->toBe(1);

    (new SyncAdAccount($acc->id, 30))->handle(app(AdsSyncService::class));
    expect($driver->adsCalls)->toBe(1)->and($driver->metricCalls)->toBe(2);
});

it('refreshes campaign statuses hourly with the light campaigns call', function () {
    $acc = admissionAccount('act_1');
    $paused = AdCampaign::create(['ad_account_id' => $acc->id, 'external_id' => 'c1', 'name' => 'One', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE']);
    Http::fake(function (Request $r) {
        if (str_contains($r->url(), 'act_1/campaigns')) {
            return Http::response(['data' => [['id' => 'c1', 'status' => 'PAUSED', 'effective_status' => 'PAUSED']]]);
        }

        return Http::response(['data' => []]);
    });

    $today = CarbonImmutable::now('Africa/Cairo')->startOfDay();
    $run = app(AdsSyncService::class)->syncAccount($acc, $today->subDays(2), $today, 'recent', false);

    expect($run->status)->toBe('ok')
        ->and($paused->fresh()->status)->toBe('PAUSED')
        ->and($paused->fresh()->effective_status)->toBe('PAUSED');
    $requests = collect(Http::recorded())->map(fn ($p) => $p[0]);
    $campaignCall = $requests->first(fn (Request $r) => str_contains($r->url(), 'act_1/campaigns'));
    expect($campaignCall->data()['fields'])->toBe('id,status,effective_status')
        ->and($requests->contains(fn (Request $r) => str_contains($r->url(), 'act_1/ads')))->toBeFalse();
});

it('keeps the run ok with a warning when the campaign status call fails', function () {
    $acc = admissionAccount('act_1');
    Http::fake(function (Request $r) {
        if (str_contains($r->url(), 'act_1/campaigns')) {
            return Http::response(['error' => ['code' => 100, 'message' => 'Unsupported get request']], 400);
        }

        return Http::response(['data' => []]);
    });

    $today = CarbonImmutable::now('Africa/Cairo')->startOfDay();
    $run = app(AdsSyncService::class)->syncAccount($acc, $today->subDays(2), $today, 'recent', false);

    expect($run->status)->toBe('ok')->and($run->error)->toContain('Campaign statuses');
});

it('clears the pending marker when the job ends, keeps it on a release', function () {
    $acc = admissionAccount();
    DB::table('ad_accounts')->where('id', $acc->id)->update(['sync_pending_since' => now()]);
    admissionUsage($acc, 80, 5);
    admissionFakeMeta();

    admissionQueuedJob(new SyncAdAccount($acc->id, 3, withAds: false))->handle(app(AdsSyncService::class));
    expect($acc->fresh()->sync_pending_since)->not->toBeNull();

    AdsApiUsage::query()->delete();
    admissionQueuedJob(new SyncAdAccount($acc->id, 3, withAds: false))->handle(app(AdsSyncService::class));
    expect($acc->fresh()->sync_pending_since)->toBeNull();

    DB::table('ad_accounts')->where('id', $acc->id)->update(['sync_pending_since' => now()]);
    (new SyncAdAccount($acc->id, 3))->failed(new RuntimeException('gave up'));
    expect($acc->fresh()->sync_pending_since)->toBeNull();
});

it('the sweeper clears pending markers older than 8 hours', function () {
    $old = AdAccount::factory()->meta()->create(['external_id' => 'act_1']);
    $fresh = AdAccount::factory()->meta()->create(['external_id' => 'act_2']);
    DB::table('ad_accounts')->where('id', $old->id)->update(['sync_pending_since' => now()->subHours(9)]);
    DB::table('ad_accounts')->where('id', $fresh->id)->update(['sync_pending_since' => now()->subHour()]);

    $this->artisan('ads:sweep-stuck-runs')->assertSuccessful();

    expect($old->fresh()->sync_pending_since)->toBeNull()->and($fresh->fresh()->sync_pending_since)->not->toBeNull();
});
