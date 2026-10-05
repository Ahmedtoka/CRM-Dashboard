<?php

namespace App\Ads;

use App\Ads\Attribution\Commands\AttributeOrdersCommand;
use App\Ads\Captions\CaptionGenerator;
use App\Ads\Captions\FakeCaptionGenerator;
use App\Ads\Captions\GeneratesCaptions;
use App\Ads\Commands\ImportArenaTokenCommand;
use App\Ads\Commands\SetupTeamCommand;
use App\Ads\Control\Commands\ClearOpenKeysCommand;
use App\Ads\Control\Commands\WritableAccountsCommand;
use App\Ads\Materials\Commands\StockWatchCommand;
use App\Ads\Platforms\DriverFactory;
use App\Ads\Platforms\Meta\UsageRecorder;
use App\Ads\Sync\Commands\BackfillAdsCommand;
use App\Ads\Sync\Commands\RefreshCreativesCommand;
use App\Ads\Sync\Commands\SweepStuckRunsCommand;
use App\Ads\Sync\Commands\SyncAdsCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class AdsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DriverFactory::class);
        $this->app->singleton(UsageRecorder::class);
        // Same switch as the bot's AI: Claude only when the ai driver is claude, else the offline generator.
        $this->app->bind(GeneratesCaptions::class, fn ($app) => config('crm.drivers.ai', 'fake') === 'claude'
            ? $app->make(CaptionGenerator::class)
            : $app->make(FakeCaptionGenerator::class));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SyncAdsCommand::class, BackfillAdsCommand::class, RefreshCreativesCommand::class, AttributeOrdersCommand::class, StockWatchCommand::class, ImportArenaTokenCommand::class, SetupTeamCommand::class, ClearOpenKeysCommand::class, SweepStuckRunsCommand::class, WritableAccountsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(SyncAdsCommand::class, ['--days=3'])
                ->hourlyAt(10)->timezone('Africa/Cairo')->withoutOverlapping()->onOneServer()->runInBackground()->appendOutputTo(storage_path('logs/ads-schedule.log'));

            $schedule->command(SyncAdsCommand::class, ['--days=30'])
                ->dailyAt('03:15')->timezone('Africa/Cairo')->withoutOverlapping()->onOneServer()->runInBackground()->appendOutputTo(storage_path('logs/ads-schedule.log'));

            $schedule->command(RefreshCreativesCommand::class, ['--days=14'])
                ->dailyAt('05:20')->timezone('Africa/Cairo')->withoutOverlapping()->onOneServer()->runInBackground()->appendOutputTo(storage_path('logs/ads-schedule.log'));

            $schedule->command(AttributeOrdersCommand::class, ['--days=35'])
                ->hourlyAt(40)->timezone('Africa/Cairo')->withoutOverlapping()->onOneServer()->runInBackground()->appendOutputTo(storage_path('logs/ads-schedule.log'));

            $schedule->command(ClearOpenKeysCommand::class)
                ->hourlyAt(25)->timezone('Africa/Cairo')->withoutOverlapping()->onOneServer()->runInBackground()->appendOutputTo(storage_path('logs/ads-schedule.log'));

            $schedule->command(StockWatchCommand::class)
                ->everyThirtyMinutes()->timezone('Africa/Cairo')->withoutOverlapping()->onOneServer()->runInBackground()->appendOutputTo(storage_path('logs/ads-schedule.log'));

            $schedule->command(SweepStuckRunsCommand::class)
                ->everyFiveMinutes()->timezone('Africa/Cairo')->withoutOverlapping(10)->onOneServer()->appendOutputTo(storage_path('logs/ads-schedule.log'));
        });
    }
}
