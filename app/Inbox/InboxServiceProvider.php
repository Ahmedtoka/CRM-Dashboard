<?php

namespace App\Inbox;

use Illuminate\Support\ServiceProvider;

class InboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CustomerResolver::class);
        $this->app->singleton(WindowPolicy::class);
        $this->app->singleton(SoftLock::class);
        $this->app->singleton(InboxIngestor::class);
        $this->app->singleton(OutboundService::class);
    }

    public function boot(): void
    {
        //
    }
}
