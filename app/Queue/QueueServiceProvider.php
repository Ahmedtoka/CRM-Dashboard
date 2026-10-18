<?php

namespace App\Queue;

use App\Models\User;
use App\Queue\Commands\QueueOrphansCommand;
use App\Queue\Commands\QueueRollupCommand;
use App\Queue\Commands\QueueTick;
use App\Queue\Events\CloseReversed;
use App\Queue\Events\WindowClosed;
use App\Queue\Scoring\ScoreListener;
use Illuminate\Auth\Events\Logout;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class QueueServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([QueueTick::class, QueueRollupCommand::class, QueueOrphansCommand::class]);
        }

        // Queue Part 2: what a window earns (the listener rescues itself, never failing the close).
        Event::listen(WindowClosed::class, [ScoreListener::class, 'windowClosed']);
        Event::listen(CloseReversed::class, [ScoreListener::class, 'closeReversed']);

        // Logging out of the CRM = «خروج» (attendance §3). A failure is reported and never breaks the logout.
        Event::listen(function (Logout $event) {
            if ($event->user instanceof User) {
                rescue(fn () => app(ShiftService::class)->checkOutEverywhere($event->user), null, report: true);
            }
        });

        // Every 30 seconds from the one-minute cron (`schedule:run` repeats it inside the minute).
        // The overlap lock expires after 2 minutes, not the default day: a tick killed half-way
        // must not stop the queue until tomorrow.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(QueueTick::class)
                ->everyThirtySeconds()
                ->withoutOverlapping(2)
                ->onOneServer();

            // Queue Part 2: repair and archive yesterday, after the analytics rollup's 00:10 run.
            $schedule->command(QueueRollupCommand::class)
                ->dailyAt('00:20')
                ->timezone(QueueService::TZ)
                ->withoutOverlapping()
                ->onOneServer();
        });
    }
}
