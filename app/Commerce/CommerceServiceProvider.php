<?php

namespace App\Commerce;

use App\Commerce\Commands\DetectOrderMismatches;
use App\Commerce\Contracts\CommerceProvider;
use App\Commerce\Listeners\DetectMismatchesAfterOrdersImport;
use App\Events\IntegrationProgress;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class CommerceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CommerceProvider::class, function ($app) {
            return match (config('crm.drivers.commerce', 'fake')) {
                'live' => $app->make(ShopifyCommerceProvider::class),
                default => $app->make(FakeCommerceProvider::class),
            };
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DetectOrderMismatches::class,
            ]);
        }

        Event::listen(IntegrationProgress::class, DetectMismatchesAfterOrdersImport::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Time-based mismatch rules (spec §6.1) have no triggering event.
            $schedule->command(DetectOrderMismatches::class)
                ->hourly()
                ->withoutOverlapping()
                ->onOneServer();
        });
    }
}
