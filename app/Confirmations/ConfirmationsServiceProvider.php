<?php

namespace App\Confirmations;

use App\Confirmations\Contracts\ConfirmationSender;
use App\Confirmations\Contracts\ShopifyOrderTagger;
use App\Confirmations\Shopify\GraphqlOrderTagger;
use App\Confirmations\WhatsApp\WhatsAppConfirmationSender;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * WhatsApp order confirmation (spec docs/superpowers/specs/2026-10-16-whatsapp-order-confirmation-design.md).
 * Each slice registers its own listeners, commands and schedules in its own register*() method below
 * to keep the parallel branches from editing the same lines.
 */
class ConfirmationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConfirmationSettings::class);
        $this->app->bind(ConfirmationSender::class, WhatsAppConfirmationSender::class);
        $this->app->bind(ShopifyOrderTagger::class, GraphqlOrderTagger::class);
    }

    public function boot(): void
    {
        $this->bootTrigger();
        $this->bootWhatsApp();
        $this->bootFollowUp();
        $this->bootTooling();
    }

    /** Slice W1: order-created trigger. */
    private function bootTrigger(): void {}

    /** Slice W2: button replies. */
    private function bootWhatsApp(): void {}

    /** Slice W3: tags, cases, notifications, sweep schedule. */
    private function bootFollowUp(): void
    {
        Event::subscribe(FollowUp\ConfirmationFollowUp::class);

        if ($this->app->runningInConsole()) {
            $this->commands([Commands\SweepOrderConfirmationsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function ($schedule) {
            $schedule->command(Commands\SweepOrderConfirmationsCommand::class)
                ->everyFiveMinutes()
                ->withoutOverlapping()
                ->onOneServer();
        });
    }

    /** Slice W5: template tooling commands. */
    private function bootTooling(): void {}
}
