<?php

use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Ads\Platforms\RateLimited;
use App\Ads\Sync\AccountSyncClaim;
use App\Ads\Sync\AdsSyncService;
use App\Ads\Sync\SyncAdAccount;
use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use App\Models\AdsSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Africa/Cairo'));
    Http::preventStrayRequests();
    config(['crm.ads.drivers.meta' => 'live', 'crm.ads.history_start' => '2026-09-01']);
});

function claimAccount(): AdAccount
{
    $c = AdPlatformConnection::factory()->meta()->create(['credentials' => ['access_token' => 'tok']]);

    return AdAccount::factory()->meta()->create(['external_id' => 'act_31', 'connection_id' => $c->id]);
}

function claimHeldBy(AdAccount $a, string $key, CarbonImmutable|Carbon\Carbon $until): void
{
    DB::table('ad_accounts')->where('id', $a->id)->update(['sync_claim_key' => $key, 'sync_claimed_until' => $until]);
}

function claimSync(AdAccount $a): AdsSyncRun
{
    $today = CarbonImmutable::now('Africa/Cairo')->startOfDay();

    return app(AdsSyncService::class)->syncAccount($a, $today->subDays(2), $today, 'recent', false);
}

it('acquires a free claim once, until it expires', function () {
    $acc = claimAccount();

    $key = AccountSyncClaim::acquire($acc, 600);
    expect($key)->toBeString()->and(AccountSyncClaim::acquire($acc, 600))->toBeNull();

    $this->travel(11)->minutes();
    expect(AccountSyncClaim::acquire($acc, 600))->toBeString()->not->toBe($key);
});

it('keeps the claim when the cache is flushed (DB-backed)', function () {
    $acc = claimAccount();
    AccountSyncClaim::acquire($acc, 600);

    Cache::flush();

    expect(AccountSyncClaim::acquire($acc, 600))->toBeNull();
});

it('extend says whether the caller still holds the claim', function () {
    $acc = claimAccount();
    $key = AccountSyncClaim::acquire($acc, 600);

    expect(AccountSyncClaim::extend($acc, $key, 600))->toBeTrue(); // same second: MariaDB reports 0 changed rows, still ours
    claimHeldBy($acc, 'thief', now()->addMinutes(30));
    expect(AccountSyncClaim::extend($acc, $key, 600))->toBeFalse();
});

it('a backfill stops with a warning when its claim was taken over', function () {
    config(['crm.ads.drivers.meta' => 'fake']);
    $acc = AdAccount::factory()->meta()->create();
    $driver = new class extends FakeAdsDriver
    {
        public int $metricCalls = 0;

        public function dailyMetrics(AdAccount $a, CarbonImmutable $from, CarbonImmutable $to): array
        {
            if (++$this->metricCalls === 1) {
                claimHeldBy($a, 'thief', now()->addMinutes(30)); // our claim expired and another sync took it
            }

            return [];
        }

        public function accountDaily(AdAccount $a, CarbonImmutable $from, CarbonImmutable $to): ?array
        {
            return null;
        }
    };
    app()->bind(FakeAdsDriver::class, fn () => $driver);

    $run = app(AdsSyncService::class)->backfill($acc, 35, batchKey: 'b-1');

    expect($driver->metricCalls)->toBe(1)
        ->and($run->error)->toContain('Backfill stopped: another sync took over the account')
        ->and($acc->fresh()->sync_claim_key)->toBe('thief'); // the other sync's claim is left alone
});

it('releases only with the matching key', function () {
    $acc = claimAccount();
    $key = AccountSyncClaim::acquire($acc, 600);

    AccountSyncClaim::release($acc, 'not-the-key');
    expect($acc->fresh()->sync_claim_key)->toBe($key);

    AccountSyncClaim::release($acc, $key);
    expect($acc->fresh()->sync_claim_key)->toBeNull()->and($acc->fresh()->sync_claimed_until)->toBeNull();
});

it('uses the job timeout plus a minute as the claim ttl', function () {
    expect(AccountSyncClaim::ttl())->toBe((new SyncAdAccount(0))->timeout + 60);
});

it('writes a skipped run and calls nothing while another sync holds the claim', function () {
    $acc = claimAccount();
    claimHeldBy($acc, 'someone-else', now()->addMinutes(30));
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);

    $run = claimSync($acc);

    Http::assertSentCount(0);
    expect($run->status)->toBe('skipped')->and($run->error)->toBe('Another sync of this account is running')
        ->and($acc->fresh()->sync_claim_key)->toBe('someone-else');
});

it('takes an expired claim, syncs and releases it', function () {
    $acc = claimAccount();
    claimHeldBy($acc, 'dead-worker', now()->subMinute());
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);

    $run = claimSync($acc);

    expect($run->status)->toBe('ok')->and($acc->fresh()->sync_claim_key)->toBeNull();
});

it('releases the claim when the sync throws', function () {
    $acc = claimAccount();
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 17, 'message' => 'limit']], 400)]);

    expect(fn () => claimSync($acc))->toThrow(RateLimited::class);

    expect($acc->fresh()->sync_claim_key)->toBeNull();
});

it('releases a queued job for 120 s when the account is busy, one skipped row per job', function () {
    $acc = claimAccount();
    claimHeldBy($acc, 'someone-else', now()->addMinutes(30));
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);
    $delays = [];
    $queueJob = Mockery::mock(Job::class)->shouldIgnoreMissing();
    $queueJob->shouldReceive('release')->andReturnUsing(function ($d) use (&$delays) {
        $delays[] = $d;
    });

    $job = new SyncAdAccount($acc->id, 3, withAds: false);
    $payload = serialize($job);
    $job->setJob($queueJob);
    $job->handle(app(AdsSyncService::class));
    $retry = unserialize($payload); // the same job coming back after its release
    $retry->setJob($queueJob);
    $retry->handle(app(AdsSyncService::class));

    expect($delays)->toBe([120, 120])
        ->and(AdsSyncRun::where('status', 'skipped')->count())->toBe(1);
    Http::assertSentCount(0);
});

it('resumes a rate-limited backfill without asking Meta for the chunks already done', function () {
    $acc = claimAccount();
    $today = CarbonImmutable::now('Africa/Cairo')->startOfDay();
    $limited = true; // chunk 2 is 2026-09-01 .. 2026-09-05
    Http::fake(function (Request $r) use (&$limited) {
        $range = json_decode((string) ($r->data()['time_range'] ?? '{}'), true);
        if ($limited && str_contains($r->url(), '/insights') && ($range['since'] ?? null) === '2026-09-01') {
            return Http::response(['error' => ['code' => 17, 'message' => 'User request limit reached']], 400);
        }

        return Http::response(['data' => []]);
    });
    $svc = app(AdsSyncService::class);

    expect(fn () => $svc->backfill($acc, 35, batchKey: 'batch-1'))->toThrow(RateLimited::class);
    $firstAttempt = count(Http::recorded());

    $limited = false;
    Cache::flush();
    $run = app(AdsSyncService::class)->backfill($acc, 35, batchKey: 'batch-1');

    $chunk1Insights = collect(Http::recorded())->map(fn ($p) => $p[0])->filter(function (Request $r) use ($today) {
        $range = json_decode((string) ($r->data()['time_range'] ?? '{}'), true);

        return str_contains($r->url(), '/insights') && ($range['until'] ?? null) === $today->toDateString();
    });
    $adLists = collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_contains($r->url(), 'act_31/ads')
        && ! str_contains((string) ($r->data()['effective_status'] ?? ''), 'ARCHIVED'));

    expect($run->status)->toBe('ok')
        ->and($chunk1Insights)->toHaveCount(2) // ad level + account level, sent once in the first attempt only
        ->and($adLists)->toHaveCount(1)
        ->and(count(Http::recorded()) - $firstAttempt)->toBe(2) // retry: chunk 2 ad level + account level
        ->and(AdsSyncRun::where('batch_key', 'batch-1')->where('status', 'ok')->count())->toBe(2)
        ->and($acc->fresh()->sync_claim_key)->toBeNull();
});

it('a queued backfill uses its run key as the batch key', function () {
    $acc = claimAccount();
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);

    $job = new SyncAdAccount($acc->id, 35, 'backfill', 'backfill');
    $job->handle(app(AdsSyncService::class));

    expect(AdsSyncRun::where('kind', 'backfill')->pluck('batch_key')->unique()->all())->toBe([$job->runKey]);
});

it('ads:backfill inline skips an account another sync holds', function () {
    $acc = claimAccount();
    claimHeldBy($acc, 'someone-else', now()->addMinutes(30));
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);

    $this->artisan('ads:backfill', ['--account' => $acc->id, '--days' => 10])
        ->expectsOutputToContain('Skipped')
        ->assertFailed();

    Http::assertSentCount(0);
});
