<?php

namespace App\Shopify;

use App\Shopify\Client\FakeShopifyTransport;
use App\Shopify\Client\HttpShopifyTransport;
use App\Shopify\Client\ShopifyClient;
use App\Shopify\Client\ShopifyTransport;
use App\Shopify\Commands\ReconcileCommand;
use App\Shopify\Commands\WebhooksCheckCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class ShopifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ShopifyTransport::class, function ($app) {
            return config('crm.shopify.driver', 'fake') === 'fake'
                ? new FakeShopifyTransport
                : $app->make(HttpShopifyTransport::class);
        });

        // One client per request/job so every collaborator shares throttle state.
        $this->app->scoped(ShopifyClient::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ReconcileCommand::class,
                WebhooksCheckCommand::class,
            ]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(ReconcileCommand::class)
                ->dailyAt('03:00')
                ->timezone('Africa/Cairo')
                ->withoutOverlapping()
                ->onOneServer();

            $schedule->command(WebhooksCheckCommand::class)
                ->everySixHours()
                ->withoutOverlapping()
                ->onOneServer();
        });
    }
}
