<?php

namespace App\Push;

use App\Push\Commands\PushTestCommand;
use App\Push\Drivers\FakePushSender;
use App\Push\Drivers\FcmPushSender;
use Illuminate\Support\ServiceProvider;

/** Push notifications to staff phones (mobile app spec D8, §6). */
class PushServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PushManager::class);
        $this->app->singleton(PushMessageFactory::class);
        // One fake per app instance so a test can read what was "sent".
        $this->app->singleton(FakePushSender::class);
        $this->app->singleton(FcmPushSender::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PushTestCommand::class]);
        }
    }
}
