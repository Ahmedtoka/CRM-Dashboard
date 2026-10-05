<?php

use App\Ads\Platforms\AdPlatformDriver;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\Data\AccountDailyTotal;
use App\Ads\Platforms\Data\DailyAdMetric;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Ads\Platforms\RateLimited;
use App\Ads\Sync\AccountSyncClaim;
use App\Ads\Sync\AdsSyncService;
use App\Ads\Sync\SyncAdAccount;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdCampaign;
use App\Models\AdDailyMetric;
use App\Models\AdPlatformConnection;
use App\Models\AdSet;
use App\Models\AdsSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['crm.ads.drivers.meta' => 'fake']);
});

/** The factory resolves FakeAdsDriver for non-live platforms, so bind the double there. */
function bindDriver(AdPlatformDriver $driver): void
{
    app()->bind(FakeAdsDriver::class, fn () => $driver);
}

/** A scriptable driver double. */
function doubleDriver(array $metrics = [], ?Throwable $adsError = null, ?Throwable $mediaError = null, ?Throwable $accountsError = null): AdPlatformDriver
{
    return new class($metrics, $adsError, $mediaError, $accountsError) extends FakeAdsDriver
    {
        public function __construct(private array $m, private ?Throwable $ae, private ?Throwable $me, private ?Throwable $ace = null) {}

        public function accounts(AdPlatformConnection $c): array
        {
            if ($this->ace) {
                throw $this->ace;
            }

            return parent::accounts($c);
        }

        public function ads(AdAccount $a): array
        {
            if ($this->ae) {
                throw $this->ae;
            }

            return [];
        }

        public function dailyMetrics(AdAccount $a, CarbonImmutable $from, CarbonImmutable $to): array
        {
            return $this->m;
        }

        public function creativeMedia(AdAccount $a, array $ids): array
        {
            if ($this->me) {
                throw $this->me;
            }

            return parent::creativeMedia($a, $ids);
        }
    };
}

function metric(string $ad, string $date, float $spend = 10, ?string $name = null, ?string $campId = null, ?string $campName = null): DailyAdMetric
{
    return new DailyAdMetric($ad, $date, $spend, 100, 5, 90, 1, 50, $name, $campId, $campName, $campId ? 's1' : null, $campId ? 'Set 1' : null);
}

it('syncs accounts, ads and daily metrics idempotently', function () {
    $c = AdPlatformConnection::factory()->create(['platform' => 'meta']);
    $svc = app(AdsSyncService::class);
    expect($svc->syncAccounts($c))->toBe(3);
    $acc = AdAccount::where('external_id', 'act_demo_main')->firstOrFail();
    $svc->syncAccount($acc, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-07'));
    $ads = Ad::count();
    $rows = AdDailyMetric::count();
    $spend = (float) AdDailyMetric::sum('spend');
    $campaigns = AdCampaign::count();
    $sets = AdSet::count();
    $svc->syncAccount($acc, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-07'));
    expect(Ad::count())->toBe($ads)->and(AdDailyMetric::count())->toBe($rows)
        ->and((float) AdDailyMetric::sum('spend'))->toBe($spend)
        ->and(AdCampaign::count())->toBe($campaigns)->and(AdSet::count())->toBe($sets)
        ->and($rows)->toBe(18 * 7)->and($campaigns)->toBe(3)->and($sets)->toBe(6);
    expect(Ad::whereNull('media_fetched_at')->count())->toBe(0)
        ->and(Ad::whereNotNull('image_url')->count())->toBe(18);
    $run = AdsSyncRun::latest('id')->first();
    expect($run->status)->toBe('ok')->and($run->ads_count)->toBe(18)->and($run->rows_count)->toBe(126);
    expect($acc->fresh()->last_synced_at)->not->toBeNull()
        ->and($c->fresh()->status)->toBe('connected')->and($c->fresh()->last_synced_at)->not->toBeNull();
});

it('records an error run and marks the connection when the api fails', function () {
    $acc = AdAccount::factory()->meta()->create();
    bindDriver(doubleDriver(adsError: new AdsApiException('Invalid token')));

    $run = app(AdsSyncService::class)->syncAccount($acc, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-02'));

    expect($run->status)->toBe('error')->and($run->error)->toContain('Invalid token')->and($run->finished_at)->not->toBeNull();
    $conn = $acc->connection->fresh();
    expect($conn->status)->toBe('error')->and($conn->last_error)->toContain('Invalid token');
});

it('creates minimal ads for metrics of ads no longer listed', function () {
    $acc = AdAccount::factory()->meta()->create();
    bindDriver(doubleDriver(metrics: [metric('gone1', '2026-09-01', 25, 'Old ad', 'c9', 'Old campaign')]));

    app(AdsSyncService::class)->syncAccount($acc, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-02'));

    $ad = Ad::where('external_id', 'gone1')->firstOrFail();
    expect(Ad::count())->toBe(1)->and($ad->name)->toBe('Old ad')->and($ad->status)->toBe('unknown')
        ->and($ad->campaign->name)->toBe('Old campaign')->and($ad->adSet->name)->toBe('Set 1')
        ->and(AdDailyMetric::count())->toBe(1);
});

it('deletes a stale row of a date the answered control does not list (total 0), and keeps rows outside the window', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->create(['ad_account_id' => $acc->id, 'external_id' => 'a1']);
    AdDailyMetric::factory()->create(['ad_id' => $ad->id, 'ad_account_id' => $acc->id, 'date' => '2026-09-02', 'spend' => 99]);
    AdDailyMetric::factory()->create(['ad_id' => $ad->id, 'ad_account_id' => $acc->id, 'date' => '2026-08-01', 'spend' => 7]);
    bindDriver(doubleDriver(metrics: [metric('a1', '2026-09-03', 12)]));

    $run = app(AdsSyncService::class)->syncAccount($acc, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-05'));

    // The fake control answered with 2026-09-03 only, so 2026-09-02 totals 0 = the payload of that date: corrected away.
    expect(AdDailyMetric::where('date', '2026-09-02')->count())->toBe(0)
        ->and($run->error)->toBeNull()
        ->and(AdDailyMetric::where('date', '2026-08-01')->count())->toBe(1)
        ->and((float) AdDailyMetric::where('date', '2026-09-03')->value('spend'))->toBe(12.0);
});

/** A double whose control totals are scripted: null = the driver has no control (TikTok, Google). */
function controlDriver(array $metrics, ?array $control): AdPlatformDriver
{
    return new class($metrics, $control) extends FakeAdsDriver
    {
        public function __construct(private array $m, private ?array $control) {}

        public function ads(AdAccount $a): array
        {
            return [];
        }

        public function dailyMetrics(AdAccount $a, CarbonImmutable $from, CarbonImmutable $to): array
        {
            return $this->m;
        }

        public function accountDaily(AdAccount $a, CarbonImmutable $from, CarbonImmutable $to): ?array
        {
            if ($this->control === null) {
                return null;
            }

            return array_map(fn ($date, $spend) => new AccountDailyTotal((string) $date, (float) $spend, 1000, 0.0, 0.0, 'EGP'), array_keys($this->control ?? []), array_values($this->control ?? []));
        }
    };
}

/** Stored rows of ads A and B on 2026-09-10; returns [account, ad B]. */
function storedAandB(float $a = 10, float $b = 7, string $bStatus = 'ACTIVE'): array
{
    $acc = AdAccount::factory()->meta()->create();
    $adA = Ad::factory()->create(['ad_account_id' => $acc->id, 'external_id' => 'A']);
    $adB = Ad::factory()->create(['ad_account_id' => $acc->id, 'external_id' => 'B', 'effective_status' => $bStatus]);
    AdDailyMetric::factory()->create(['ad_id' => $adA->id, 'ad_account_id' => $acc->id, 'date' => '2026-09-10', 'spend' => $a]);
    AdDailyMetric::factory()->create(['ad_id' => $adB->id, 'ad_account_id' => $acc->id, 'date' => '2026-09-10', 'spend' => $b]);

    return [$acc, $adB];
}

function syncDay(AdAccount $acc): AdsSyncRun
{
    return app(AdsSyncService::class)->syncAccount($acc, CarbonImmutable::parse('2026-09-10'), CarbonImmutable::parse('2026-09-10'));
}

it('deletes a stale row when the payload equals the account total (platform correction wins)', function () {
    [$acc, $adB] = storedAandB();
    bindDriver(controlDriver([metric('A', '2026-09-10', 10)], ['2026-09-10' => 10]));

    $run = syncDay($acc);

    expect($run->status)->toBe('ok')->and($run->error)->toBeNull()
        ->and(AdDailyMetric::where('ad_id', $adB->id)->exists())->toBeFalse()
        ->and(AdDailyMetric::where('ad_account_id', $acc->id)->count())->toBe(1);
});

it('keeps a stale row when the account total still includes it, and says so', function () {
    [$acc, $adB] = storedAandB();
    bindDriver(controlDriver([metric('A', '2026-09-10', 10)], ['2026-09-10' => 17]));

    $run = syncDay($acc);

    expect($run->status)->toBe('ok')
        ->and($run->error)->toContain('Kept 1 rows on 1 dates (first: 2026-09-10: payload 10.00 vs account 17.00)')
        ->and(AdDailyMetric::where('ad_id', $adB->id)->exists())->toBeTrue();
});

it('keeps a stale row when the platform has no control (null, e.g. TikTok)', function () {
    [$acc, $adB] = storedAandB();
    bindDriver(controlDriver([metric('A', '2026-09-10', 10)], null));

    $run = syncDay($acc);

    expect($run->status)->toBe('ok')
        ->and($run->error)->toContain('Kept 1 rows on 1 dates (first: 2026-09-10: payload 10.00 vs account n/a)')
        ->and(AdDailyMetric::where('ad_id', $adB->id)->exists())->toBeTrue();
});

it('keeps stale rows with a warning when the control call fails', function () {
    [$acc, $adB] = storedAandB();
    $driver = new class extends FakeAdsDriver
    {
        public function ads(AdAccount $a): array
        {
            return [];
        }

        public function dailyMetrics(AdAccount $a, CarbonImmutable $from, CarbonImmutable $to): array
        {
            return [metric('A', '2026-09-10', 10)];
        }

        public function accountDaily(AdAccount $a, CarbonImmutable $from, CarbonImmutable $to): ?array
        {
            throw new AdsApiException('control refused');
        }
    };
    bindDriver($driver);

    $run = syncDay($acc);

    expect($run->status)->toBe('ok')
        ->and($run->error)->toContain('Account totals: control refused')->toContain('Kept 1 rows on 1 dates')
        ->and(AdDailyMetric::where('ad_id', $adB->id)->exists())->toBeTrue();
});

it('collapses the kept warning into one summary line', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->create(['ad_account_id' => $acc->id, 'external_id' => 'B']);
    foreach (['2026-09-10', '2026-09-11', '2026-09-12'] as $d) {
        AdDailyMetric::factory()->create(['ad_id' => $ad->id, 'ad_account_id' => $acc->id, 'date' => $d, 'spend' => 7]);
    }
    bindDriver(controlDriver([metric('A', '2026-09-10', 10)], null));

    $run = app(AdsSyncService::class)->syncAccount($acc, CarbonImmutable::parse('2026-09-10'), CarbonImmutable::parse('2026-09-12'));

    expect($run->error)->toBe('Kept 3 rows on 3 dates (first: 2026-09-10: payload 10.00 vs account n/a)');
});

it('deletes the stale row of an ad in any status when the account total agrees (the control is the only guard)', function (string $status) {
    [$acc, $adB] = storedAandB(bStatus: $status);
    bindDriver(controlDriver([metric('A', '2026-09-10', 10)], ['2026-09-10' => 10]));

    syncDay($acc);

    expect(AdDailyMetric::where('ad_id', $adB->id)->exists())->toBeFalse();
})->with(['ARCHIVED', 'DELETED', 'GONE']);

it('uses the larger of the tolerance percent and one currency unit', function () {
    config(['crm.ads.control_tolerance_pct' => 0.5]);
    [$acc, $adB] = storedAandB(1000, 7);
    bindDriver(controlDriver([metric('A', '2026-09-10', 1000)], ['2026-09-10' => 1004.9]));
    syncDay($acc);
    expect(AdDailyMetric::where('ad_id', $adB->id)->exists())->toBeFalse();

    [$acc2, $adB2] = storedAandB(1000, 7);
    bindDriver(controlDriver([metric('A', '2026-09-10', 1000)], ['2026-09-10' => 1006]));
    syncDay($acc2);
    expect(AdDailyMetric::where('ad_id', $adB2->id)->exists())->toBeTrue();

    [$acc3, $adB3] = storedAandB(10, 7);
    bindDriver(controlDriver([metric('A', '2026-09-10', 10)], ['2026-09-10' => 10.99]));
    syncDay($acc3);
    expect(AdDailyMetric::where('ad_id', $adB3->id)->exists())->toBeFalse();
});

it('keeps every row on an empty payload, as before', function () {
    [$acc] = storedAandB();
    bindDriver(controlDriver([], ['2026-09-10' => 0]));

    $run = syncDay($acc);

    expect($run->error)->toContain('Empty metrics payload')
        ->and(AdDailyMetric::where('ad_account_id', $acc->id)->count())->toBe(2);
});

it('keeps metrics and an ok run when creative media fails', function () {
    $acc = AdAccount::factory()->meta()->create();
    Ad::factory()->create(['ad_account_id' => $acc->id, 'external_id' => 'a1', 'status' => 'ACTIVE', 'media_fetched_at' => null]);
    bindDriver(doubleDriver(metrics: [metric('a1', '2026-09-01', 5, 'A', 'c1', 'C')], mediaError: new AdsApiException('media boom')));

    $run = app(AdsSyncService::class)->syncAccount($acc, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-02'));

    expect($run->status)->toBe('ok')->and($run->error)->toContain('media boom')
        ->and(AdDailyMetric::count())->toBe(1)->and($acc->connection->fresh()->status)->toBe('connected');
});

it('refreshes creatives of recently active ads', function () {
    $c = AdPlatformConnection::factory()->create(['platform' => 'meta']);
    $svc = app(AdsSyncService::class);
    $svc->syncAccounts($c);
    $acc = AdAccount::where('external_id', 'act_demo_main')->firstOrFail();
    $today = CarbonImmutable::now('Africa/Cairo');
    $svc->syncAccount($acc, $today->subDays(2), $today);
    Ad::query()->update(['image_url' => null]);

    expect($svc->refreshCreatives($acc, 14))->toBe(18)
        ->and(Ad::whereNotNull('image_url')->count())->toBe(18);
});

it('dispatches one job per active account from ads:sync', function () {
    Queue::fake();
    AdAccount::factory()->meta()->create(['external_id' => 'act_1']);
    AdAccount::factory()->meta()->create(['external_id' => 'act_2']);
    AdAccount::factory()->meta()->create(['external_id' => 'act_3', 'is_active' => false]);

    $this->artisan('ads:sync', ['--days' => 3])->assertSuccessful();

    Queue::assertPushed(SyncAdAccount::class, 2);
});

it('runs SyncAdAccount on the long queue, never the 60-s default worker', function () {
    $job = new SyncAdAccount(1, 90, 'backfill');
    expect($job->queue)->toBe('commercelong')
        ->and($job->timeout)->toBe(3600)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->uniqueFor)->toBeGreaterThan($job->timeout)
        ->and($job->connection)->toBeNull();

    config(['queue.default' => 'redis']);
    expect((new SyncAdAccount(1))->connection)->toBe('redislong');
});

it('limits ads:sync by platform', function () {
    Queue::fake();
    AdAccount::factory()->meta()->create(['external_id' => 'act_1']);
    AdAccount::factory()->tiktok()->create(['external_id' => 't1']);

    $this->artisan('ads:sync', ['--platform' => 'tiktok'])->assertSuccessful();
    Queue::assertPushed(SyncAdAccount::class, 1);
});

it('runs synchronously with --now', function () {
    $a = AdAccount::factory()->meta()->create();

    $this->artisan('ads:sync', ['--account' => $a->id, '--now' => true, '--days' => 2])->assertSuccessful();
    expect(Ad::where('ad_account_id', $a->id)->count())->toBe(18);
});

it('backfills in 30-day chunks newest first', function () {
    $acc = AdAccount::factory()->meta()->create();
    $this->artisan('ads:backfill', ['--account' => $acc->id, '--days' => 70])->assertSuccessful();

    $today = CarbonImmutable::now('Africa/Cairo');
    $runs = AdsSyncRun::where('kind', 'backfill')->orderBy('id')->get();
    expect($runs)->toHaveCount(3)
        ->and($runs[0]->to_date->toDateString())->toBe($today->toDateString())
        ->and($runs[0]->from_date->toDateString())->toBe($today->subDays(29)->toDateString())
        ->and($runs[1]->to_date->toDateString())->toBe($today->subDays(30)->toDateString())
        ->and($runs[2]->from_date->toDateString())->toBe($today->subDays(69)->toDateString());
});

it('registers the ads schedule in Africa/Cairo', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'ads:'));
    expect($events->map(fn ($e) => $e->expression)->all())->toContain('10 * * * *', '15 3 * * *', '20 5 * * *')
        ->and($events->every(fn ($e) => $e->timezone === 'Africa/Cairo'))->toBeTrue();
});

it('throws on rate limit, records an error run and leaves the connection connected', function () {
    $acc = AdAccount::factory()->meta()->create();
    bindDriver(doubleDriver(adsError: new RateLimited('quota')));

    expect(fn () => app(AdsSyncService::class)->syncAccount($acc, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-02')))
        ->toThrow(RateLimited::class);

    $run = AdsSyncRun::latest('id')->first();
    expect($run->status)->toBe('error')->and($run->error)->toContain('quota')->and($run->finished_at)->not->toBeNull()
        ->and($acc->connection->fresh()->status)->toBe('connected');
});

it('marks the run error and rethrows on unexpected exceptions', function () {
    $acc = AdAccount::factory()->meta()->create();
    bindDriver(doubleDriver(adsError: new RuntimeException('boom')));

    expect(fn () => app(AdsSyncService::class)->syncAccount($acc, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-02')))
        ->toThrow(RuntimeException::class);

    $run = AdsSyncRun::latest('id')->first();
    expect($run->status)->toBe('error')->and($run->error)->toContain('boom');
});

it('releases the job for 900 seconds on a real queue job when rate limited', function () {
    $acc = AdAccount::factory()->meta()->create();
    bindDriver(doubleDriver(adsError: new RateLimited('quota')));
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('release')->once()->with(900);

    $job = new SyncAdAccount($acc->id, 3);
    $job->setJob($queueJob);
    $job->handle(app(AdsSyncService::class));
});

it('rethrows rate limits when run inline', function () {
    $acc = AdAccount::factory()->meta()->create();
    bindDriver(doubleDriver(adsError: new RateLimited('quota')));

    expect(fn () => SyncAdAccount::dispatchSync($acc->id, 3))->toThrow(RateLimited::class);
});

it('warns and fails ads:sync --now for a rate limited account without printing success', function () {
    $limited = AdAccount::factory()->meta()->create(['name' => 'Limited One']);
    bindDriver(doubleDriver(adsError: new RateLimited('quota access_token=SECRET123')));

    $this->artisan('ads:sync', ['--account' => $limited->id, '--now' => true])
        ->expectsOutputToContain('Failed Limited One')
        ->doesntExpectOutputToContain('Synced Limited One')
        ->doesntExpectOutputToContain('SECRET123')
        ->assertFailed();
});

it('continues with other accounts when one fails and exits non-zero', function () {
    AdAccount::factory()->meta()->create(['name' => 'First']);
    AdAccount::factory()->meta()->create(['name' => 'Second']);
    $calls = new ArrayObject(['n' => 0]);
    app()->bind(FakeAdsDriver::class, fn () => new class(function () use ($calls) {
        if ($calls['n']++ === 0) {
            throw new RateLimited('quota');
        }
    }) extends FakeAdsDriver
    {
        public function __construct(private Closure $hook) {}

        public function ads(AdAccount $a): array
        {
            ($this->hook)();

            return parent::ads($a);
        }
    });

    $code = Artisan::call('ads:sync', ['--now' => true]);
    $out = Artisan::output();

    expect($code)->toBe(1)->and($out)->toContain('Failed First')->toContain('Synced Second')->not->toContain('Synced First');
});

it('fails ads:backfill for an erroring account but keeps going', function () {
    AdAccount::factory()->meta()->create(['name' => 'Bad']);
    bindDriver(doubleDriver(adsError: new RateLimited('quota')));

    $this->artisan('ads:backfill', ['--days' => 10])->expectsOutputToContain('Failed Bad')->assertFailed();
});

it('does not request media for minimal ads built from metrics', function () {
    $acc = AdAccount::factory()->meta()->create();
    bindDriver(doubleDriver(metrics: [metric('gone1', '2026-09-01', 5, 'Old', 'c9', 'C')], mediaError: new AdsApiException('media boom')));

    $run = app(AdsSyncService::class)->syncAccount($acc, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-02'));

    expect($run->status)->toBe('ok')->and($run->error)->toBeNull();
});

it('keeps existing rows when the platform returns an empty metrics payload', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->create(['ad_account_id' => $acc->id, 'external_id' => 'a1']);
    AdDailyMetric::factory()->create(['ad_id' => $ad->id, 'ad_account_id' => $acc->id, 'date' => '2026-09-02']);
    bindDriver(doubleDriver(metrics: []));

    $run = app(AdsSyncService::class)->syncAccount($acc, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-05'));

    expect(AdDailyMetric::count())->toBe(1)->and($run->status)->toBe('ok')->and($run->error)->toContain('Empty metrics payload');
});

it('discovers new accounts during the deep sync, skipping disabled connections', function () {
    AdPlatformConnection::factory()->create(['platform' => 'meta', 'status' => 'connected']);
    AdPlatformConnection::factory()->create(['platform' => 'tiktok', 'status' => 'disabled']);

    $this->artisan('ads:sync', ['--days' => 30])->assertSuccessful();

    expect(AdAccount::where('platform', 'meta')->count())->toBe(3)->and(AdAccount::where('platform', 'tiktok')->count())->toBe(0);
});

it('does not run discovery on the light sync', function () {
    Queue::fake();
    AdPlatformConnection::factory()->create(['platform' => 'meta']);
    $this->artisan('ads:sync', ['--days' => 3])->assertSuccessful();
    expect(AdAccount::count())->toBe(0);
});

it('warns and records a discovery failure without failing the deep sync', function () {
    Queue::fake();
    $c = AdPlatformConnection::factory()->create(['platform' => 'meta', 'name' => 'Conn A']);
    bindDriver(doubleDriver(accountsError: new AdsApiException('Token expired')));

    $this->artisan('ads:sync', ['--days' => 30])->expectsOutputToContain('Account discovery failed for Conn A')->assertSuccessful();

    expect($c->fresh()->status)->toBe('error')->and($c->fresh()->last_error)->toContain('Token expired');
});

it('creates many accounts in one test without id collisions', function () {
    AdAccount::factory()->meta()->count(30)->create();
    AdAccount::factory()->tiktok()->count(30)->create();
    AdAccount::factory()->google()->count(30)->create();
    expect(AdAccount::count())->toBe(90);
});

it('marks the run error when creativeMedia throws a non-api exception', function () {
    $acc = AdAccount::factory()->meta()->create();
    Ad::factory()->create(['ad_account_id' => $acc->id, 'external_id' => 'a1', 'status' => 'ACTIVE', 'media_fetched_at' => null]);
    bindDriver(doubleDriver(metrics: [metric('a1', '2026-09-01', 5, 'A', 'c1', 'C')], mediaError: new RuntimeException('media bug')));

    expect(fn () => app(AdsSyncService::class)->syncAccount($acc, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-02')))
        ->toThrow(RuntimeException::class);

    $run = AdsSyncRun::latest('id')->first();
    expect($run->status)->toBe('error')->and($run->error)->toContain('media bug')->and($run->finished_at)->not->toBeNull()
        ->and(AdDailyMetric::count())->toBe(1);
});

it('marks the run error when the driver cannot be resolved', function () {
    $acc = AdAccount::factory()->meta()->create(['platform' => 'bogus']);

    expect(fn () => app(AdsSyncService::class)->syncAccount($acc, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-02')))
        ->toThrow(ValueError::class);

    expect(AdsSyncRun::latest('id')->first()->status)->toBe('error');
});

it('warns and continues when a command hits an unexpected exception', function () {
    AdAccount::factory()->meta()->create(['name' => 'Odd']);
    bindDriver(doubleDriver(adsError: new RuntimeException('kaboom')));

    $this->artisan('ads:sync', ['--now' => true])->expectsOutputToContain('Failed Odd')->assertFailed();
    $this->artisan('ads:backfill', ['--days' => 5])->expectsOutputToContain('Failed Odd')->assertFailed();
});

it('skips a backfill for an account whose sync claim is held', function () {
    $acc = AdAccount::factory()->meta()->create(['name' => 'Locked']);
    $key = AccountSyncClaim::acquire($acc, 60);
    expect($key)->toBeString();

    $this->artisan('ads:backfill', ['--account' => $acc->id, '--days' => 5])->expectsOutputToContain('busy')->assertFailed();

    expect(AdsSyncRun::where('status', '!=', 'skipped')->count())->toBe(0);
    AccountSyncClaim::release($acc, $key);
    $this->artisan('ads:backfill', ['--account' => $acc->id, '--days' => 5])->assertSuccessful();
});

it('uses the same lock key as the unique job', function () {
    expect(SyncAdAccount::lockKey(7))->toBe('laravel_unique_job:'.SyncAdAccount::class.(new SyncAdAccount(7))->uniqueId());
});

it('queues the nightly 30-day sync even while the hourly job of the same account still waits', function () {
    Queue::fake();
    $acc = AdAccount::factory()->meta()->create(['name' => 'Busy']);

    SyncAdAccount::dispatch($acc->id, 3);
    SyncAdAccount::dispatch($acc->id, 3);   // the next hourly run: still dropped
    SyncAdAccount::dispatch($acc->id, 30);

    Queue::assertPushed(SyncAdAccount::class, 2);
    Queue::assertPushed(SyncAdAccount::class, fn (SyncAdAccount $job) => $job->days === 30);
});

it('queues a backfill by platform account id for the worker to retry', function () {
    Queue::fake();
    $a = AdAccount::factory()->meta()->create(['external_id' => 'act_111']);
    AdAccount::factory()->meta()->create(['external_id' => 'act_222']);

    $this->artisan('ads:backfill', ['--external' => 'act_111', '--queue' => true])->expectsOutputToContain('Queued')->assertSuccessful();

    Queue::assertPushed(SyncAdAccount::class, 1);
    Queue::assertPushed(SyncAdAccount::class, fn (SyncAdAccount $j) => $j->accountId === $a->id && $j->kind === 'backfill' && $j->days === 90
        && $j->tries === 30 && $j->maxExceptions === 3);
});

it('runs a sync once even when redis hands the same job back after it finished', function () {
    $acc = AdAccount::factory()->meta()->create();
    bindDriver(doubleDriver());
    $queueJob = Mockery::mock(Job::class)->shouldIgnoreMissing();

    $job = new SyncAdAccount($acc->id, 3);
    $copy = unserialize(serialize($job));   // what Redis re-queues: the same payload
    foreach ([$job, $copy] as $j) {
        $j->setJob($queueJob);
        $j->handle(app(AdsSyncService::class));
    }

    expect(AdsSyncRun::count())->toBe(1);

    (new SyncAdAccount($acc->id, 3))->handle(app(AdsSyncService::class));   // a new dispatch still runs
    expect(AdsSyncRun::count())->toBe(2);
});

it('tries again after a rate-limit release instead of taking it for done', function () {
    $acc = AdAccount::factory()->meta()->create();
    bindDriver(doubleDriver(adsError: new RateLimited('quota')));
    $queueJob = Mockery::mock(Job::class)->shouldIgnoreMissing();
    $job = new SyncAdAccount($acc->id, 3);
    $payload = serialize($job);
    $job->setJob($queueJob);
    $job->handle(app(AdsSyncService::class));

    bindDriver(doubleDriver());
    $retry = unserialize($payload);
    $retry->setJob($queueJob);
    $retry->handle(app(AdsSyncService::class));

    expect(AdsSyncRun::where('status', 'ok')->count())->toBe(1);
});

it('reads the ad list once per backfill, on its first chunk', function () {
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
            return null; // the control fetch is its own call (A2), not an ad-level metrics read
        }
    };
    bindDriver($driver);

    app(AdsSyncService::class)->backfill($acc, 90);

    expect($driver->adsCalls)->toBe(1)->and($driver->metricCalls)->toBe(3);
});

it('still runs a job queued before the run key existed', function () {
    $acc = AdAccount::factory()->meta()->create();
    bindDriver(doubleDriver());
    $job = new SyncAdAccount($acc->id, 3);
    $job->runKey = null;

    $job->handle(app(AdsSyncService::class));

    expect(AdsSyncRun::count())->toBe(1);
});
