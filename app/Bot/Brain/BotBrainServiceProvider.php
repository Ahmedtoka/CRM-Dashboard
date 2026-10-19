<?php

namespace App\Bot\Brain;

use App\Bot\Agent\ReplyGuards;
use App\Bot\Brain\Costs\CostWatchCommand;
use App\Bot\Brain\Trace\TracedReplyGuards;
use App\Bot\Brain\Trace\TurnTrace;
use App\Enums\SenderType;
use App\Models\Message;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * «عقل البوت» (b2, 2026-10-19): the per-reply trace, staff feedback on bot replies, the cost view
 * and the test console. The trace is fed from outside the engine: the HTTP client's response event
 * (every Messages API call), the bot messages created, the reply guards and BotRun::save.
 */
class BotBrainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TurnTrace::class);
        $this->app->bind(ReplyGuards::class, TracedReplyGuards::class);
    }

    public function boot(): void
    {
        $this->commands([CostWatchCommand::class]);
        $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command('bot:cost-watch')->hourly()->withoutOverlapping());

        Event::listen(ResponseReceived::class, function (ResponseReceived $event) {
            if (! str_starts_with($event->request->url(), 'https://api.anthropic.com/v1/messages')) {
                return;
            }

            $seconds = $event->response->transferStats?->getTransferTime();
            app(TurnTrace::class)->recordModelCall($event->request->data(), (array) ($event->response->json() ?? []), $seconds !== null ? (int) round($seconds * 1000) : 0);
        });

        Message::created(function (Message $m) {
            if ($m->sender_type === SenderType::Bot && $m->conversation_id !== null) {
                app(TurnTrace::class)->recordMessage($m);
            }
        });
    }
}
