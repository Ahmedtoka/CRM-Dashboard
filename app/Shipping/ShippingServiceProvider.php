<?php

namespace App\Shipping;

use App\Shipping\Contracts\ShippingProvider;
use Illuminate\Support\ServiceProvider;

class ShippingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ShippingProvider::class, function ($app) {
            return match (config('crm.drivers.shipping', 'fake')) {
                default => $app->make(FakeShippingProvider::class),
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
