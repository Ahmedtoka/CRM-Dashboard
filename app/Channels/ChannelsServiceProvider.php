<?php

namespace App\Channels;

use App\Channels\Adapters\TikTok\FakeTikTokApi;
use App\Channels\Adapters\TikTok\LiveTikTokMessagingApi;
use App\Channels\Adapters\TikTok\TikTokMessagingApi;
use App\Channels\Commands\CheckChannelHealth;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class ChannelsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChannelRegistry::class);

        // TikTok direct messages (x6, 2026-10-24): the fake recorder unless the channels AND the TikTok DM driver are live.
        $this->app->singleton(TikTokMessagingApi::class, function ($app) {
            $fake = config('crm.drivers.channels') === 'fake' || config('services.tiktok.driver', 'fake') !== 'live';

            return $fake ? new FakeTikTokApi : $app->make(LiveTikTokMessagingApi::class);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CheckChannelHealth::class]);
        }

        // Settings → Integrations: daily token / webhook / traffic check (Cairo morning,
        // before the team starts, so a broken channel is flagged before customers wait).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(CheckChannelHealth::class)
                ->dailyAt('08:00')
                ->timezone('Africa/Cairo')
                ->withoutOverlapping()
                ->onOneServer();
        });
    }
}
