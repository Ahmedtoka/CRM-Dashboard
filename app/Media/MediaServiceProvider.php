<?php

namespace App\Media;

use App\Media\Commands\BackfillThumbnailsCommand;
use App\Media\Commands\PruneMediaOrphans;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MediaInspector::class);
        $this->app->singleton(MediaPolicy::class);
        $this->app->singleton(MediaStorage::class);
        $this->app->singleton(Thumbnailer::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PruneMediaOrphans::class, BackfillThumbnailsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(PruneMediaOrphans::class)->hourly()->withoutOverlapping()->onOneServer();
        });
    }
}
