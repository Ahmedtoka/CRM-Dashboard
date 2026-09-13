<?php

namespace App\Analytics;

use App\Analytics\Commands\LatencyReportCommand;
use App\Analytics\Commands\LoadTestCommand;
use App\Analytics\Commands\PruneLatencyCommand;
use App\Analytics\Commands\RollupDaily;
use App\Analytics\Commands\SeedLoadDatasetCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue as QueueBase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MetricsService::class);
        $this->app->singleton(PresenceTracker::class);
        $this->app->singleton(LatencyRecorder::class);

        // crm:loadtest never sleeps directly (ruling): it resolves a sleeper Closure from
        // the container, defaulting to a real usleep(); tests bind a no-op.
        $this->app->bind('crm.loadtest.sleeper', fn () => function (int $microseconds): void {
            usleep($microseconds);
        });

        // LatencyRecorder::inbound()'s end checkpoint reads "now" through this closure
        // instead of calling microtime()/now() directly, so tests can assert an exact
        // duration without a real sleep. Default: the real clock.
        $this->app->bind('crm.latency.clock', fn () => fn (): float => microtime(true));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                RollupDaily::class,
                LoadTestCommand::class,
                LatencyReportCommand::class,
                SeedLoadDatasetCommand::class,
                PruneLatencyCommand::class,
            ]);
        }

        $this->registerQueueLatencyTracking();

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Close out yesterday shortly after Cairo midnight.
            $schedule->command(RollupDaily::class, [now(MetricsService::TZ)->subDay()->toDateString()])
                ->dailyAt('00:10')
                ->timezone(MetricsService::TZ)
                ->withoutOverlapping();

            // Keep today's rows fresh.
            $schedule->command(RollupDaily::class)
                ->everyFifteenMinutes()
                ->timezone(MetricsService::TZ)
                ->withoutOverlapping();

            // Retention (fix round 1): latency_samples is written on every inbound/outbound/
            // list/queue event when enabled, so it needs pruning like any other event log.
            $schedule->command(PruneLatencyCommand::class)
                ->dailyAt('04:00')
                ->timezone(MetricsService::TZ)
                ->withoutOverlapping();
        });
    }

    /**
     * Driver-agnostic queue-wait sample (fix round 1, item 3): tag every job payload with
     * the exact push instant via a payload hook (works for sync/redis/database/sqs alike,
     * unlike reading the database queue's `jobs` table), then measure push -> pickup at
     * Queue::before, which fires for every connection.
     */
    private function registerQueueLatencyTracking(): void
    {
        QueueBase::createPayloadUsing(fn () => ['pushedAt' => microtime(true)]);

        Queue::before(function (JobProcessing $event) {
            if (! config('crm.latency.enabled')) {
                return;
            }

            try {
                $payload = $event->job->payload();
                $pushedAt = $payload['pushedAt'] ?? null;

                // Missing on jobs pushed before this hook existed (or by a queue driver we
                // don't control the payload of) — nothing to measure, skip quietly.
                if ($pushedAt === null) {
                    return;
                }

                $pushedAtMs = (int) round(((float) $pushedAt) * 1000);
                $pickedUpAtMs = (int) floor(microtime(true) * 1000);
                $ref = $event->job->getJobId() ?: ($payload['uuid'] ?? uniqid('job_', true));

                $this->app->make(LatencyRecorder::class)->queueWait($ref, $pushedAtMs, $pickedUpAtMs, [
                    'queue' => $event->job->getQueue(),
                    'job' => $payload['displayName'] ?? $event->job->resolveName(),
                ]);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
