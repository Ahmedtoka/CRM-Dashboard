<?php

namespace App\Legal;

use App\Legal\Commands\PruneRetentionCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class LegalServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PruneRetentionCommand::class]);
        }

        // The privacy policy's retention promise (crm.legal.retention_months).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('crm:prune-retention')
                ->dailyAt('04:00')
                ->timezone('Africa/Cairo')
                ->withoutOverlapping()
                ->onOneServer();
        });
    }
}
