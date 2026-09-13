<?php

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;

/**
 * Fix round 1, item 3: queue-wait must be driver-agnostic, not just the database
 * queue. AnalyticsServiceProvider tags every job payload with a `pushedAt` (via
 * Queue::createPayloadUsing) and records push -> pickup on Queue::before, which
 * fires for every connection (sync included).
 */
it('records a queue-wait sample from a synthetic JobProcessing event', function () {
    config(['crm.latency.enabled' => true]);

    $pushedAt = microtime(true) - 2.0; // pushed ~2s ago
    $payload = json_encode([
        'uuid' => 'test-job-uuid',
        'displayName' => 'App\\Fake\\FakeJob',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'pushedAt' => $pushedAt,
        'data' => [],
    ]);

    $job = new SyncJob(app(), $payload, 'sync', 'default');

    event(new JobProcessing('sync', $job));

    $row = DB::table('latency_samples')->where('kind', 'queue')->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->duration_ms)->toBeGreaterThanOrEqual(1900)
        ->and((int) $row->duration_ms)->toBeLessThanOrEqual(2500);

    // SyncJob::getQueue() always reports 'sync' regardless of the queue name passed in.
    $meta = json_decode($row->meta, true);
    expect($meta['queue'])->toBe('sync')
        ->and($meta['job'])->toBe('App\\Fake\\FakeJob');
});

it('skips recording when the job payload has no pushedAt', function () {
    config(['crm.latency.enabled' => true]);

    $payload = json_encode([
        'uuid' => 'legacy-job-uuid',
        'displayName' => 'App\\Fake\\LegacyJob',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => [],
    ]);

    $job = new SyncJob(app(), $payload, 'sync', 'default');

    event(new JobProcessing('sync', $job));

    expect(DB::table('latency_samples')->where('kind', 'queue')->count())->toBe(0);
});

it('does not record queue wait when latency tracking is disabled', function () {
    config(['crm.latency.enabled' => false]);

    $payload = json_encode([
        'uuid' => 'disabled-job-uuid',
        'displayName' => 'App\\Fake\\FakeJob',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'pushedAt' => microtime(true) - 2.0,
        'data' => [],
    ]);

    $job = new SyncJob(app(), $payload, 'sync', 'default');

    event(new JobProcessing('sync', $job));

    expect(DB::table('latency_samples')->where('kind', 'queue')->count())->toBe(0);
});
