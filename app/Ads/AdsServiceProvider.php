<?php

namespace App\Ads;

use App\Ads\Platforms\DriverFactory;
use Illuminate\Support\ServiceProvider;

class AdsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DriverFactory::class);
    }
}
