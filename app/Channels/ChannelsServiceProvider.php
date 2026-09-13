<?php

namespace App\Channels;

use Illuminate\Support\ServiceProvider;

class ChannelsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChannelRegistry::class);
    }

    public function boot(): void
    {
        //
    }
}
