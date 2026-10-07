<?php

namespace App\Simulator;

use App\Simulator\Commands\RunLoadTest;
use App\Simulator\Commands\SimulateTraffic;
use App\Simulator\LoadTest\LoadTest;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class SimulatorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Simulator::class);
        $this->app->singleton(LoadTest::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SimulateTraffic::class, RunLoadTest::class]);
        }

        // Production load test (2026-10-07): emits the waves that are due. A cheap no-op when no
        // plan is active (the gate is read first, then one indexed query).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(RunLoadTest::class, ['tick'])->everyMinute()->withoutOverlapping(10)->onOneServer();
        });
    }
}
