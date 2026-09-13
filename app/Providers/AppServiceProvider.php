<?php

namespace App\Providers;

use App\Analytics\ActivityLogger;
use App\Analytics\PresenceTracker;
use App\Enums\ActorType;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Web session login/logout audit (API token login/logout log in Api\V1\AuthController).
        Event::listen(function (Login $event) {
            if ($event->user instanceof User) {
                app(ActivityLogger::class)->log(ActorType::User, $event->user, ActivityLogger::USER_LOGIN, null, null, ['device' => 'web', 'guard' => $event->guard]);
            }
        });

        Event::listen(function (Logout $event) {
            if ($event->user instanceof User) {
                app(PresenceTracker::class)->end($event->user);
                app(ActivityLogger::class)->log(ActorType::User, $event->user, ActivityLogger::USER_LOGOUT, null, null, ['device' => 'web', 'guard' => $event->guard]);
            }
        });

        // Staging health check (GET /up/crm) reads this to report the scheduler as alive.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->call(fn () => Cache::forever('crm:scheduler_heartbeat', now()->toISOString()))
                ->everyMinute()
                ->name('crm-scheduler-heartbeat')
                ->withoutOverlapping();
        });
    }
}
