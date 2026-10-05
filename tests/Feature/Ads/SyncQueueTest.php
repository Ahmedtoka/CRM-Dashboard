<?php

use App\Ads\Sync\QueueInspector;
use App\Ads\Sync\SyncAdAccount;
use App\Models\AdAccount;
use App\Models\AdsSyncRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['crm.ads.drivers.meta' => 'fake']);
});

it('queues the ads sync on commercelong by default', function () {
    expect((new SyncAdAccount(1))->queue)->toBe('commercelong')
        ->and(SyncAdAccount::queueName())->toBe('commercelong');
});

it('queues the ads sync on the configured adssync lane, on redislong when the default is redis', function () {
    config(['crm.ads.sync.queue' => 'adssync']);
    expect((new SyncAdAccount(1, 90, 'backfill'))->queue)->toBe('adssync');

    config(['queue.default' => 'redis']);
    $job = new SyncAdAccount(1);
    expect($job->queue)->toBe('adssync')->and($job->connection)->toBe('redislong');
});

it('falls back to commercelong when the setting is blank', function () {
    config(['crm.ads.sync.queue' => '']);

    expect((new SyncAdAccount(1))->queue)->toBe('commercelong');
});

it('dispatches the scheduled sync on the configured lane', function () {
    config(['crm.ads.sync.queue' => 'adssync']);
    Queue::fake();
    AdAccount::factory()->meta()->create(['external_id' => 'act_1']);

    $this->artisan('ads:sync', ['--days' => 3])->assertSuccessful();

    Queue::assertPushedOn('adssync', SyncAdAccount::class);
});

it('the waiting list reads the ads sync lane as well as commercelong', function () {
    config(['crm.ads.sync.queue' => 'adssync']);
    $asked = [];
    (new QueueInspector(function (string $q) use (&$asked) {
        $asked[] = $q;

        return [[], []];
    }))->waiting();

    expect($asked)->toBe(['commercelong', 'adssync']);
});

function queueDoctorRow(string $check): ?string
{
    Cache::put('crm:scheduler_heartbeat', now()->toISOString());
    Artisan::call('ads:doctor', ['--markdown' => true, '--no-network' => true]);
    foreach (explode("\n", Artisan::output()) as $line) {
        if (str_starts_with($line, '| Queue | ') && str_contains($line, "| {$check}")) {
            return $line;
        }
    }

    return null;
}

it('doctor fails when the adssync lane has ready jobs and no sync finished in 15 minutes', function () {
    config(['crm.ads.sync.queue' => 'adssync']);
    $this->mock(QueueInspector::class, fn ($m) => $m->shouldReceive('lengths')->andReturn(['ready' => 4, 'delayed' => 0, 'reserved' => 0]));
    $acc = AdAccount::factory()->meta()->create();
    AdsSyncRun::factory()->create(['ad_account_id' => $acc->id, 'status' => 'ok', 'started_at' => now()->subHour(), 'finished_at' => now()->subMinutes(40)]);

    expect(queueDoctorRow('adssync worker'))->toContain('| fail |');
});

it('doctor passes the adssync lane while syncs keep finishing', function () {
    config(['crm.ads.sync.queue' => 'adssync']);
    $this->mock(QueueInspector::class, fn ($m) => $m->shouldReceive('lengths')->andReturn(['ready' => 4, 'delayed' => 0, 'reserved' => 0]));
    $acc = AdAccount::factory()->meta()->create();
    AdsSyncRun::factory()->create(['ad_account_id' => $acc->id, 'status' => 'ok', 'run_key' => 'queued-1', 'started_at' => now()->subMinutes(6), 'finished_at' => now()->subMinutes(5)]);

    expect(queueDoctorRow('adssync worker'))->toContain('| ok |');
});

it('doctor ignores inline runs when judging the adssync worker', function () {
    config(['crm.ads.sync.queue' => 'adssync']);
    $this->mock(QueueInspector::class, fn ($m) => $m->shouldReceive('lengths')->andReturn(['ready' => 4, 'delayed' => 0, 'reserved' => 0]));
    $acc = AdAccount::factory()->meta()->create();
    AdsSyncRun::factory()->create(['ad_account_id' => $acc->id, 'status' => 'ok', 'run_key' => null, 'started_at' => now()->subMinutes(6), 'finished_at' => now()->subMinutes(5)]);

    expect(queueDoctorRow('adssync worker'))->toContain('| fail |');
});

it('doctor has no adssync worker row while the lane is commercelong', function () {
    expect(queueDoctorRow('adssync worker'))->toBeNull();
});
