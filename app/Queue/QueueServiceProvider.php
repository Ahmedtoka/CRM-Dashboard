<?php

namespace App\Queue;

use Illuminate\Support\ServiceProvider;

class QueueServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Task 10 registers queue:tick here.
    }
}
