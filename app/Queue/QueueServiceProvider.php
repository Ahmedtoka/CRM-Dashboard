<?php

namespace App\Queue;

use App\Queue\Commands\QueueTick;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class QueueServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([QueueTick::class]);
        }

        // Every 30 seconds from the one-minute cron (`schedule:run` repeats it inside the minute).
        // The overlap lock expires after 2 minutes, not the default day: a tick killed half-way
        // must not stop the queue until tomorrow.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(QueueTick::class)
                ->everyThirtySeconds()
                ->withoutOverlapping(2)
                ->onOneServer();
        });
    }
}
