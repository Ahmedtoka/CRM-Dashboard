<?php

use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Ads\Platforms\Meta\MetaAdsApi;
use App\Ads\Platforms\RateLimited;
use App\Ads\Sync\AdsSyncService;
use App\Ads\Sync\SyncAdAccount;
use App\Models\AdAccount;
use App\Models\AdAccountDaily;
use App\Models\AdCampaign;
use App\Models\AdDailyMetric;
use App\Models\AdPlatformConnection;
use App\Models\AdsApiUsage;
use App\Models\AdsSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
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
    admissionUsage(admissionAccount(), 92, 3, regain: 20);

    try {
        app(MetaAdsApi::class)->admit('act_11');
        $this->fail('expected RateLimited');
    } catch (RateLimited $e) {
        expect($e->retryAfterSeconds)->toBe(1200)->and($e->getMessage())->toContain('deferred by Meta quota')->toContain('retry in 20 min');
    }
});

it('waits at least until the busy reading leaves the 15-minute window', function () {
    admissionUsage(admissionAccount(), 80, 2); // leaves the window in 13 minutes, Meta gave no regain time

    try {
        app(MetaAdsApi::class)->admit('act_11');
        $this->fail('expected RateLimited');
    } catch (RateLimited $e) {
        expect($e->retryAfterSeconds)->toBe(780)->and($e->getMessage())->toContain('retry in 13 min');
    }
});

it('admits when the busy reading is older than 15 minutes', function () {
    admissionUsage(admissionAccount(), 80, 20);

    app(MetaAdsApi::class)->admit('act_11');
})->throwsNoExceptions();

it('admits when admission is disabled', function () {
    config(['crm.ads.sync.admission_enabled' => false]);
    admissionUsage(admissionAccount(), 95, 2);

    app(MetaAdsApi::class)->admit('act_11');
})->throwsNoExceptions();

it('only looks at the usage of the account being synced', function () {
    admissionAccount();
    admissionUsage(AdAccount::factory()->meta()->create(['external_id' => 'act_22']), 95, 2);

    app(MetaAdsApi::class)->admit('act_11');
})->throwsNoExceptions();

it('never blocks a write, nor a read outside a sync run', function () {
    $acc = admissionAccount();
    admissionUsage($acc, 99, 1);
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1', 'data' => []])]);

    app(MetaAdsApi::class)->post('tok', 'act_11/ads', ['name' => 'x']);
    app(MetaAdsApi::class)->get('tok', 'act_11/campaigns');

    Http::assertSentCount(2);
});

it('admits once per run: a high reading during the run does not refuse the later calls', function () {
    $acc = admissionAccount('act_1');
    AdCampaign::create(['ad_account_id' => $acc->id, 'external_id' => 'c1', 'name' => 'One', 'status' => 'ACTIVE']);
    $usage = ['x-business-use-case-usage' => '{"1":[{"type":"ads_insights","call_count":78,"total_cputime":1,"total_time":1,"estimated_time_to_regain_access":0}]}'];
    Http::fake(function (Request $r) use ($usage) {
        if (str_contains($r->url(), 'act_1/insights') && ($r->data()['level'] ?? null) === 'ad') {
            return Http::response(['data' => [['ad_id' => 'a1', 'date_start' => '2026-10-04', 'spend' => '10', 'campaign_id' => 'c1']]], 200, $usage);
        }
        if (str_contains($r->url(), 'act_1/insights')) {
            return Http::response(['data' => [['date_start' => '2026-10-04', 'spend' => '10']]]);
        }
        if (str_contains($r->url(), 'act_1/campaigns')) {
            return Http::response(['data' => [['id' => 'c1', 'status' => 'PAUSED', 'effective_status' => 'PAUSED']]]);
        }

        return Http::response(['data' => []]);
    });

    $today = CarbonImmutable::now('Africa/Cairo')->startOfDay();
    $run = app(AdsSyncService::class)->syncAccount($acc, $today->subDays(2), $today, 'recent', false);

    $urls = collect(Http::recorded())->map(fn ($p) => $p[0]);
    expect($run->status)->toBe('ok')
        ->and(AdsApiUsage::where('max_pct', 78)->exists())->toBeTrue()
        ->and($urls->filter(fn (Request $r) => str_contains($r->url(), 'act_1/insights'))->count())->toBe(2) // ad level + control
        ->and($urls->contains(fn (Request $r) => str_contains($r->url(), 'act_1/campaigns')))->toBeTrue()
        ->and(AdDailyMetric::count())->toBe(1)
        ->and(AdCampaign::where('external_id', 'c1')->value('status'))->toBe('PAUSED');
});

it('keeps the rows of a read above 85 percent, stops paging and warns on the run', function () {
    $acc = admissionAccount('act_1');
    $usage = ['x-business-use-case-usage' => '{"1":[{"type":"ads_insights","call_count":90,"total_cputime":1,"total_time":1,"estimated_time_to_regain_access":0}]}'];
    Http::fake(function (Request $r) use ($usage) {
        if (str_contains($r->url(), 'page2')) {
            return Http::response(['data' => [['ad_id' => 'a3', 'date_start' => '2026-10-04', 'spend' => '1']]]);
        }
        if (str_contains($r->url(), 'act_1/insights') && ($r->data()['level'] ?? null) === 'ad') {
            return Http::response(['data' => [
                ['ad_id' => 'a1', 'date_start' => '2026-10-04', 'spend' => '10'],
                ['ad_id' => 'a2', 'date_start' => '2026-10-04', 'spend' => '5'],
            ], 'paging' => ['next' => 'https://graph.facebook.com/v23.0/act_1/insights?page2=1']], 200, $usage);
        }

        return Http::response(['data' => []]);
    });

    $today = CarbonImmutable::now('Africa/Cairo')->startOfDay();
    $run = app(AdsSyncService::class)->syncAccount($acc, $today->subDays(2), $today, 'recent', false);

    expect($run->status)->toBe('ok')
        ->and($run->error)->toContain('Ad metrics: stopped paging at 90% usage (2 rows kept)')
        ->and(AdDailyMetric::count())->toBe(2);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'page2'));
});

it('a control cut short by high usage is no control: nothing stale is deleted', function () {
    $acc = admissionAccount('act_1');
    $usage = ['x-business-use-case-usage' => '{"1":[{"type":"ads_insights","call_count":95,"total_cputime":1,"total_time":1}]}'];
    Http::fake(function (Request $r) use ($usage) {
        if (str_contains($r->url(), 'act_1/insights') && ($r->data()['level'] ?? null) === 'account') {
            return Http::response(['data' => [['date_start' => '2026-10-05', 'spend' => '0']], 'paging' => ['next' => 'https://graph.facebook.com/v23.0/act_1/insights?page2=1']], 200, $usage);
        }

        return Http::response(['data' => []]);
    });

    $today = CarbonImmutable::now('Africa/Cairo')->startOfDay();
    $run = app(AdsSyncService::class)->syncAccount($acc, $today->subDays(2), $today, 'recent', false);

    expect($run->status)->toBe('ok')->and($run->error)->toContain('Account totals: stopped paging at 95% usage')
        ->and(AdAccountDaily::count())->toBe(0);
});

it('carries the regain time on a rate-limit error response', function () {
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
    $header = '{"11":[{"type":"ads_insights","call_count":100,"total_cputime":1,"total_time":1,"estimated_time_to_regain_access":12}]}';
    $withRegain = true;
    Http::fake(function () use (&$withRegain, $header) {
        return Http::response(['error' => ['code' => 17, 'message' => 'limit']], 400, $withRegain ? ['x-business-use-case-usage' => $header] : []);
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

it('takes a pending marker over after 2 hours, not before', function () {
    Queue::fake();
    $old = AdAccount::factory()->meta()->create(['external_id' => 'act_1']);
    $recent = AdAccount::factory()->meta()->create(['external_id' => 'act_2']);
    DB::table('ad_accounts')->where('id', $old->id)->update(['sync_pending_since' => now()->subMinutes(121)]);
    DB::table('ad_accounts')->where('id', $recent->id)->update(['sync_pending_since' => now()->subMinutes(100)]);

    $this->artisan('ads:sync', ['--days' => 3])->assertSuccessful();

    Queue::assertPushed(SyncAdAccount::class, 1);
    Queue::assertPushed(SyncAdAccount::class, fn (SyncAdAccount $j) => $j->accountId === $old->id);
});

it('undoes the pending marker when the dispatch fails', function () {
    $a = AdAccount::factory()->meta()->create(['external_id' => 'act_1', 'name' => 'Lane Down']);
    Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('Connection refused [tcp://127.0.0.1:6379]'));

    $this->artisan('ads:sync', ['--days' => 3])->expectsOutputToContain('Failed to queue Lane Down')->assertFailed();

    expect($a->fresh()->sync_pending_since)->toBeNull();
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

it('keeps the run ok with a warning when the campaign status call is rate limited after metrics are stored', function () {
    $acc = admissionAccount('act_1');
    Http::fake(function (Request $r) {
        if (str_contains($r->url(), 'act_1/campaigns')) {
            return Http::response(['error' => ['code' => 17, 'message' => 'User request limit reached']], 400);
        }

        return Http::response(['data' => []]);
    });

    $today = CarbonImmutable::now('Africa/Cairo')->startOfDay();
    $run = app(AdsSyncService::class)->syncAccount($acc, $today->subDays(2), $today, 'recent', false);

    expect($run->status)->toBe('ok')->and($run->error)->toContain('Campaign statuses: User request limit reached');
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

it('clears the pending marker when the job ends, refreshes it on a release (heartbeat)', function () {
    $acc = admissionAccount();
    DB::table('ad_accounts')->where('id', $acc->id)->update(['sync_pending_since' => now()->subMinutes(110)]);
    admissionUsage($acc, 80, 5);
    admissionFakeMeta();

    admissionQueuedJob(new SyncAdAccount($acc->id, 3, withAds: false))->handle(app(AdsSyncService::class));
    expect($acc->fresh()->sync_pending_since->diffInSeconds(now(), true))->toBeLessThan(5); // a live job is never taken over

    AdsApiUsage::query()->delete();
    admissionQueuedJob(new SyncAdAccount($acc->id, 3, withAds: false))->handle(app(AdsSyncService::class));
    expect($acc->fresh()->sync_pending_since)->toBeNull();

    DB::table('ad_accounts')->where('id', $acc->id)->update(['sync_pending_since' => now()]);
    (new SyncAdAccount($acc->id, 3))->failed(new RuntimeException('gave up'));
    expect($acc->fresh()->sync_pending_since)->toBeNull();
});

it('refreshes the pending marker when an attempt starts', function () {
    config(['crm.ads.drivers.meta' => 'fake']);
    $acc = AdAccount::factory()->meta()->create();
    DB::table('ad_accounts')->where('id', $acc->id)->update(['sync_pending_since' => now()->subMinutes(110)]);
    $probe = new stdClass;
    app()->bind(FakeAdsDriver::class, fn () => new class($probe) extends FakeAdsDriver
    {
        public function __construct(private stdClass $probe) {}

        public function dailyMetrics(AdAccount $a, CarbonImmutable $from, CarbonImmutable $to): array
        {
            $this->probe->seen = AdAccount::find($a->id)->sync_pending_since;

            return [];
        }
    });

    (new SyncAdAccount($acc->id, 3, withAds: false))->handle(app(AdsSyncService::class));

    expect($probe->seen->diffInSeconds(now(), true))->toBeLessThan(5)->and($acc->fresh()->sync_pending_since)->toBeNull();
});

it('the nightly deep job never touches the hourly pending marker', function () {
    config(['crm.ads.drivers.meta' => 'fake']);
    $acc = AdAccount::factory()->meta()->create();
    $marker = now()->subMinutes(30)->startOfSecond();
    DB::table('ad_accounts')->where('id', $acc->id)->update(['sync_pending_since' => $marker]);

    (new SyncAdAccount($acc->id, 30))->handle(app(AdsSyncService::class));
    (new SyncAdAccount($acc->id, 30))->failed(new RuntimeException('gave up'));

    expect($acc->fresh()->sync_pending_since->equalTo($marker))->toBeTrue();
});

it('the sweeper clears pending markers older than 2 hours', function () {
    $old = AdAccount::factory()->meta()->create(['external_id' => 'act_1']);
    $fresh = AdAccount::factory()->meta()->create(['external_id' => 'act_2']);
    DB::table('ad_accounts')->where('id', $old->id)->update(['sync_pending_since' => now()->subMinutes(121)]);
    DB::table('ad_accounts')->where('id', $fresh->id)->update(['sync_pending_since' => now()->subMinutes(100)]);

    $this->artisan('ads:sweep-stuck-runs')->assertSuccessful();

    expect($old->fresh()->sync_pending_since)->toBeNull()->and($fresh->fresh()->sync_pending_since)->not->toBeNull();
});
