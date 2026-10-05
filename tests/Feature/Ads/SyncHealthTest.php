<?php

use App\Ads\Sync\SyncAdAccount;
use App\Models\AdAccount;
use App\Models\AdsApiUsage;
use App\Models\AdsSyncRun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

function syncHealthRunning(AdAccount $acc, int $minutesAgo, ?string $key = null): AdsSyncRun
{
    return AdsSyncRun::create([
        'ad_account_id' => $acc->id, 'platform' => 'meta', 'kind' => 'recent', 'status' => 'running',
        'started_at' => now()->subMinutes($minutesAgo), 'run_key' => $key,
    ]);
}

it('sweeps runs stuck longer than the job timeout plus ten minutes', function () {
    $acc = AdAccount::factory()->meta()->create();
    $stuck = syncHealthRunning($acc, 75);
    $fresh = syncHealthRunning($acc, 10);

    Artisan::call('ads:sweep-stuck-runs');

    expect($stuck->fresh()->status)->toBe('error')
        ->and($stuck->fresh()->error)->toBe('Worker stopped before the run finished')
        ->and($stuck->fresh()->finished_at)->not->toBeNull()
        ->and($fresh->fresh()->status)->toBe('running');
});

it('marks only the run of the failed job as error', function () {
    $acc = AdAccount::factory()->meta()->create();
    $job = new SyncAdAccount($acc->id);
    $mine = syncHealthRunning($acc, 5, $job->runKey);
    $other = syncHealthRunning($acc, 5, 'another-key');

    $job->failed(new RuntimeException('boom access_token=abc123secret'));

    expect($mine->fresh()->status)->toBe('error')
        ->and($mine->fresh()->error)->not->toContain('abc123secret')
        ->and($mine->fresh()->finished_at)->not->toBeNull()
        ->and($other->fresh()->status)->toBe('running');
});

it('writes every ads schedule line to the schedule log and schedules the sweeper', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'ads:'));

    expect($events->count())->toBeGreaterThan(1)
        ->and($events->every(fn ($e) => str_ends_with(str_replace(chr(92), '/', (string) $e->output), 'storage/logs/ads-schedule.log')))->toBeTrue();

    $sweep = $events->first(fn ($e) => str_contains($e->command, 'ads:sweep-stuck-runs'));
    expect($sweep)->not->toBeNull()->and($sweep->expression)->toBe('*/5 * * * *')->and($sweep->withoutOverlapping)->toBeTrue();
});

it('prunes usage telemetry older than 35 days only', function () {
    $row = fn (int $days) => AdsApiUsage::create(['header' => 'x-ad-account-usage', 'max_pct' => 1, 'recorded_at' => now()->subDays($days)]);
    $old = $row(40);
    $recent = $row(10);

    Artisan::call('ads:sweep-stuck-runs');

    expect(AdsApiUsage::find($old->id))->toBeNull()->and(AdsApiUsage::find($recent->id))->not->toBeNull();
});
