<?php

namespace App\Simulator;

use App\Simulator\Commands\SimulateTraffic;
use Illuminate\Support\ServiceProvider;

class SimulatorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Simulator::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SimulateTraffic::class]);
        }
    }
}
