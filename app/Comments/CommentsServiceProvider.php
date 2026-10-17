<?php

namespace App\Comments;

use App\Comments\Commands\PollTikTokAdCommentsCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class CommentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CommentIngestor::class);
        $this->app->singleton(CommentActions::class);
        $this->app->singleton(CommentBot::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PollTikTokAdCommentsCommand::class]);
        }

        // TikTok ad comments have no webhook (E1): poll every few minutes; the command itself is a no-op unless live.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $minutes = max(1, (int) config('crm.tiktok_ad_comments.every_minutes', 3));
            $schedule->command(PollTikTokAdCommentsCommand::class)
                ->cron("*/{$minutes} * * * *")->withoutOverlapping(10)->onOneServer()->runInBackground();
        });
    }
}
