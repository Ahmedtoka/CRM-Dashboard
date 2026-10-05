<?php

namespace App\Ads;

use App\Ads\Attribution\Commands\AttributeOrdersCommand;
use App\Ads\Attribution\Commands\BackfillReferralsCommand;
use App\Ads\Attribution\Commands\RestoreAttributionCommand;
use App\Ads\Captions\CaptionGenerator;
use App\Ads\Captions\FakeCaptionGenerator;
use App\Ads\Captions\GeneratesCaptions;
use App\Ads\Commands\ImportArenaTokenCommand;
use App\Ads\Commands\SetupTeamCommand;
use App\Ads\Control\Commands\AdsAuthorityCommand;
use App\Ads\Control\Commands\ClearOpenKeysCommand;
use App\Ads\Control\Commands\WritableAccountsCommand;
use App\Ads\Control\Commands\WritesSwitchCommand;
use App\Ads\Doctor\DoctorCommand;
use App\Ads\Health\Commands\GateCommand;
use App\Ads\Health\Commands\HealthCommand;
use App\Ads\Health\QueueHeartbeat;
use App\Ads\Materials\Commands\StockWatchCommand;
use App\Ads\Platforms\DriverFactory;
use App\Ads\Platforms\Meta\UsageRecorder;
use App\Ads\Reports\AdsQuery;
use App\Ads\Reports\Commands\ReconcileCommand;
use App\Ads\Sync\Commands\BackfillAdsCommand;
use App\Ads\Sync\Commands\PruneHistoryCommand;
use App\Ads\Sync\Commands\RefreshCreativesCommand;
use App\Ads\Sync\Commands\SweepStuckRunsCommand;
use App\Ads\Sync\Commands\SyncAdsCommand;
use App\Ads\Sync\Commands\TokenProbeCommand;
use App\Ads\Sync\HistoryWindow;
use App\Ads\Sync\SyncAdAccount;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class AdsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DriverFactory::class);
        $this->app->singleton(UsageRecorder::class);
        // One per request: the account-control read inside AdsQuery is memoised per filter and shared by every report.
        $this->app->scoped(AdsQuery::class);
        // Same switch as the bot's AI: Claude only when the ai driver is claude, else the offline generator.
        $this->app->bind(GeneratesCaptions::class, fn ($app) => config('crm.drivers.ai', 'fake') === 'claude'
            ? $app->make(CaptionGenerator::class)
            : $app->make(FakeCaptionGenerator::class));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SyncAdsCommand::class, BackfillAdsCommand::class, RefreshCreativesCommand::class, AttributeOrdersCommand::class, StockWatchCommand::class, ImportArenaTokenCommand::class, SetupTeamCommand::class, ClearOpenKeysCommand::class, SweepStuckRunsCommand::class, WritableAccountsCommand::class, AdsAuthorityCommand::class, WritesSwitchCommand::class, DoctorCommand::class, PruneHistoryCommand::class, BackfillReferralsCommand::class, RestoreAttributionCommand::class, TokenProbeCommand::class, HealthCommand::class, GateCommand::class, ReconcileCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(SyncAdsCommand::class, ['--days=3'])
                ->hourlyAt(10)->timezone('Africa/Cairo')->withoutOverlapping()->onOneServer()->runInBackground()->appendOutputTo(storage_path('logs/ads-schedule.log'));

            $schedule->command(SyncAdsCommand::class, ['--days=30'])
                ->dailyAt('03:15')->timezone('Africa/Cairo')->withoutOverlapping()->onOneServer()->runInBackground()->appendOutputTo(storage_path('logs/ads-schedule.log'));

            $schedule->command(RefreshCreativesCommand::class, ['--days=14'])
                ->dailyAt('05:50')->timezone('Africa/Cairo')->withoutOverlapping()->onOneServer()->runInBackground()->appendOutputTo(storage_path('logs/ads-schedule.log'));

            $schedule->command(AttributeOrdersCommand::class, ['--days=35'])
                ->hourlyAt(40)->timezone('Africa/Cairo')->withoutOverlapping()->onOneServer()->runInBackground()->appendOutputTo(storage_path('logs/ads-schedule.log'));

            $schedule->command(ClearOpenKeysCommand::class)
                ->hourlyAt(25)->timezone('Africa/Cairo')->withoutOverlapping()->onOneServer()->runInBackground()->appendOutputTo(storage_path('logs/ads-schedule.log'));

            $schedule->command(StockWatchCommand::class)
                ->everyThirtyMinutes()->timezone('Africa/Cairo')->withoutOverlapping()->onOneServer()->runInBackground()->appendOutputTo(storage_path('logs/ads-schedule.log'));

            $schedule->command(HealthCommand::class)
                ->everyFiveMinutes()->timezone('Africa/Cairo')->withoutOverlapping(10)->onOneServer()->appendOutputTo(storage_path('logs/ads-schedule.log'));

            // One heartbeat job per queue the ads depend on: when it stops landing, a worker is gone (ads:health, queue:*).
            foreach (array_values(array_unique(['default', 'commercelong', SyncAdAccount::queueName()])) as $queue) {
                $connection = $queue !== 'default' && config('queue.default') === 'redis' ? 'redislong' : null;
                $schedule->job(new QueueHeartbeat($queue), $queue, $connection)->name("ads:queue-heartbeat:{$queue}")->everyFiveMinutes()->onOneServer(); // own mutex per queue: one shared name would let only the first dispatch
            }

            $schedule->command(TokenProbeCommand::class)
                ->dailyAt('06:10')->timezone('Africa/Cairo')->withoutOverlapping()->onOneServer()->runInBackground()->appendOutputTo(storage_path('logs/ads-schedule.log'));

            // After the deep sync (03:15) has had time to run: only refreshes each account's complete_from, prints nothing.
            $schedule->command(ReconcileCommand::class, ['--from='.HistoryWindow::start()->toDateString(), '--quiet-update'])
                ->dailyAt('06:30')->timezone('Africa/Cairo')->withoutOverlapping()->onOneServer()->runInBackground()->appendOutputTo(storage_path('logs/ads-schedule.log'));

            $schedule->command(SweepStuckRunsCommand::class)
                ->everyFiveMinutes()->timezone('Africa/Cairo')->withoutOverlapping(10)->onOneServer()->appendOutputTo(storage_path('logs/ads-schedule.log'));
        });
    }
}
