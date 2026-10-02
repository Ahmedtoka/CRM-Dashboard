<?php

use App\Ads\Platforms\AdPlatformDriver;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\Data\DailyAdMetric;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Ads\Platforms\RateLimited;
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
use Illuminate\Support\Facades\Cache;
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

it('removes stale metric rows inside the synced window and keeps the rest', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->create(['ad_account_id' => $acc->id, 'external_id' => 'a1']);
    AdDailyMetric::factory()->create(['ad_id' => $ad->id, 'ad_account_id' => $acc->id, 'date' => '2026-09-02', 'spend' => 99]);
    AdDailyMetric::factory()->create(['ad_id' => $ad->id, 'ad_account_id' => $acc->id, 'date' => '2026-08-01', 'spend' => 7]);
    bindDriver(doubleDriver(metrics: [metric('a1', '2026-09-03', 12)]));

    app(AdsSyncService::class)->syncAccount($acc, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-05'));

    expect(AdDailyMetric::where('date', '2026-09-02')->count())->toBe(0)
        ->and(AdDailyMetric::where('date', '2026-08-01')->count())->toBe(1)
        ->and((float) AdDailyMetric::where('date', '2026-09-03')->value('spend'))->toBe(12.0);
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

it('skips a backfill for an account whose sync lock is held', function () {
    $acc = AdAccount::factory()->meta()->create(['name' => 'Locked']);
    $lock = Cache::lock(SyncAdAccount::lockKey($acc->id), 60);
    expect($lock->get())->toBeTrue();

    $this->artisan('ads:backfill', ['--account' => $acc->id, '--days' => 5])->expectsOutputToContain('busy')->assertFailed();

    expect(AdsSyncRun::count())->toBe(0);
    $lock->release();
    $this->artisan('ads:backfill', ['--account' => $acc->id, '--days' => 5])->assertSuccessful();
});

it('uses the same lock key as the unique job', function () {
    expect(SyncAdAccount::lockKey(7))->toBe('laravel_unique_job:'.SyncAdAccount::class.(new SyncAdAccount(7))->uniqueId());
});
