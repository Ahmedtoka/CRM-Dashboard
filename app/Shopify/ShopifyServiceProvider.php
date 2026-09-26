<?php

namespace App\Shopify;

use App\Shopify\Client\FakeShopifyTransport;
use App\Shopify\Client\HttpShopifyTransport;
use App\Shopify\Client\ShopifyClient;
use App\Shopify\Client\ShopifyTransport;
use App\Shopify\Commands\ReconcileCommand;
use App\Shopify\Commands\ReconcileCountsCommand;
use App\Shopify\Commands\SyncShippingCommand;
use App\Shopify\Commands\PurgeStoreDataCommand;
use App\Shopify\Commands\WebhooksCheckCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class ShopifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ShopifyTransport::class, function ($app) {
            // Strict: only the exact value 'live' reaches a real store; a typo,
            // different casing or 'off' must never fall through to live calls.
            return config('crm.shopify.driver', 'fake') === 'live'
                ? $app->make(HttpShopifyTransport::class)
                : new FakeShopifyTransport;
        });

        // One client per request/job so every collaborator shares throttle state.
        $this->app->scoped(ShopifyClient::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ReconcileCommand::class,
                ReconcileCountsCommand::class,
                SyncShippingCommand::class,
                PurgeStoreDataCommand::class,
                WebhooksCheckCommand::class,
            ]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(ReconcileCommand::class)
                ->dailyAt('03:00')
                ->timezone('Africa/Cairo')
                ->withoutOverlapping()
                ->onOneServer();

            // Shipping fees can change in Shopify at any time and no webhook reports it.
            $schedule->command(SyncShippingCommand::class)
                ->dailyAt('03:30')
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
