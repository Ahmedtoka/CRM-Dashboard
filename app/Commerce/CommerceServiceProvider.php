<?php

namespace App\Commerce;

use App\Commerce\Contracts\CommerceProvider;
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
        //
    }
}
