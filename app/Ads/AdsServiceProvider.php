<?php

namespace App\Ads;

use App\Ads\Attribution\Commands\AttributeOrdersCommand;
use App\Ads\Commands\ImportArenaTokenCommand;
use App\Ads\Materials\Commands\StockWatchCommand;
use App\Ads\Platforms\DriverFactory;
use App\Ads\Sync\Commands\BackfillAdsCommand;
use App\Ads\Sync\Commands\RefreshCreativesCommand;
use App\Ads\Sync\Commands\SyncAdsCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class AdsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DriverFactory::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SyncAdsCommand::class, BackfillAdsCommand::class, RefreshCreativesCommand::class, AttributeOrdersCommand::class, StockWatchCommand::class, ImportArenaTokenCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(SyncAdsCommand::class, ['--days=3'])
                ->hourlyAt(10)->timezone('Africa/Cairo')->withoutOverlapping()->onOneServer();

            $schedule->command(SyncAdsCommand::class, ['--days=30'])
                ->dailyAt('03:15')->timezone('Africa/Cairo')->withoutOverlapping()->onOneServer();

            $schedule->command(RefreshCreativesCommand::class, ['--days=14'])
                ->dailyAt('05:20')->timezone('Africa/Cairo')->withoutOverlapping()->onOneServer();

            $schedule->command(AttributeOrdersCommand::class, ['--days=35'])
                ->hourlyAt(40)->timezone('Africa/Cairo')->withoutOverlapping()->onOneServer();

            $schedule->command(StockWatchCommand::class)
                ->everyThirtyMinutes()->timezone('Africa/Cairo')->withoutOverlapping()->onOneServer();
        });
    }
}
