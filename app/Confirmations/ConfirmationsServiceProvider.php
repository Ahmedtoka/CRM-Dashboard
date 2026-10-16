<?php

namespace App\Confirmations;

use App\Confirmations\Contracts\ConfirmationSender;
use App\Confirmations\Contracts\ShopifyOrderTagger;
use App\Confirmations\Shopify\GraphqlOrderTagger;
use App\Confirmations\WhatsApp\ConfirmationMessageWatcher;
use App\Confirmations\WhatsApp\ConfirmationReplyHandler;
use App\Confirmations\WhatsApp\WhatsAppConfirmationSender;
use App\Inbox\InboundInterceptors;
use App\Models\Conversation;
use App\Models\Message;
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
    private function bootTrigger(): void
    {
        // Fully qualified on purpose: keeps the shared import block free of merge conflicts between slices.
        \Illuminate\Support\Facades\Event::listen(
            \App\Confirmations\Events\ShopifyOrderCreated::class,
            \App\Confirmations\Listeners\ScheduleOrderConfirmation::class,
        );
    }

    /** Slice W2: button replies. */
    private function bootWhatsApp(): void
    {
        Message::updated(fn (Message $m) => app(ConfirmationMessageWatcher::class)->updated($m));

        // Button taps, the edit text and typed «تمام» / «الغي» are answered before the bot sees them.
        $this->app->make(InboundInterceptors::class)->register(
            fn (Message $m, Conversation $c) => $this->app->make(ConfirmationReplyHandler::class)->handle($m, $c),
        );
    }

    /** Slice W3: tags, cases, notifications, sweep schedule. */
    private function bootFollowUp(): void
    {
        // Listed one by one (not Event::subscribe): the listener and its Shopify client are only built when an event fires.
        foreach (FollowUp\ConfirmationFollowUp::LISTENERS as $event => $method) {
            Event::listen($event, [FollowUp\ConfirmationFollowUp::class, $method]);
        }

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
