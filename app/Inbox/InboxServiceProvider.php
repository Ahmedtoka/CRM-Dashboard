<?php

namespace App\Inbox;

use App\Inbox\Commands\PruneUserNotifications;
use App\Inbox\Outcomes\Commands\CloseIdleEpisodes;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class InboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CustomerResolver::class);
        $this->app->singleton(WindowPolicy::class);
        $this->app->singleton(SoftLock::class);
        $this->app->singleton(InboxIngestor::class);
        $this->app->singleton(OutboundService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PruneUserNotifications::class, CloseIdleEpisodes::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(PruneUserNotifications::class)->daily()->withoutOverlapping()->onOneServer();
            $schedule->command(CloseIdleEpisodes::class)->hourly()->withoutOverlapping()->onOneServer();
        });
    }
}
