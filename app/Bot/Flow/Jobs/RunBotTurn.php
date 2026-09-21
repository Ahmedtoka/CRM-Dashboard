<?php

namespace App\Bot\Flow\Jobs;

use App\Bot\BotEngine;
use App\Bot\Flow\ReplyScheduler;
use App\Bot\Flows\WaitingReply;
use App\Enums\Handler;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class RunBotTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** An overlapped turn is released and retried (final fix wave I4), so tries are mostly lock waits. */
    public int $tries = 10;

    public int $maxExceptions = 3;

    public int $timeout = 75;

    public function __construct(public readonly int $conversationId)
    {
        $this->onQueue('bot');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("conv:{$this->conversationId}"))->releaseAfter(10)->expireAfter(120)];
    }

    public function handle(): void
    {
        $c = Conversation::find($this->conversationId);

        if ($c === null) {
            return;
        }

        // Handed to a person and still waiting: she gets one reassurance (owner, 2026-09-21)
        // instead of silence, and the bot stays out of the conversation otherwise.
        if ($c->handler !== Handler::Bot) {
            app(WaitingReply::class)->maybeSend($c);

            return;
        }

        if ($c->bot_due_at && $c->bot_due_at->isFuture()) {
            return; // a newer message moved the due time; its own dispatch will run
        }

        $burst = app(ReplyScheduler::class)->burst($c);
        $lastId = $burst->last()?->id;
        $previousMarker = $c->bot_state['last_turn_message_id'] ?? null;

        if ($lastId === null || $previousMarker === $lastId) {
            return; // nothing new, or this burst was already answered (retry / duplicate dispatch)
        }

        // Marked before handleTurn runs so a retry after a successful send never
        // sends twice. If handleTurn throws, the catch below undoes this marker
        // unless a reply already went out — otherwise a retry would see the
        // marker already matching and silently drop the customer's burst.
        $c->forceFill(['bot_state' => array_merge($c->bot_state ?? [], ['last_turn_message_id' => $lastId])])->save();

        $beforeMaxId = (int) $c->messages()->max('id');

        try {
            app(BotEngine::class)->handleTurn($c, $burst);
        } catch (Throwable $e) {
            $replied = Message::where('conversation_id', $c->id)
                ->where('id', '>', $beforeMaxId)
                ->whereIn('sender_type', [SenderType::Bot->value, SenderType::System->value])
                ->exists();

            if (! $replied) {
                $c->forceFill(['bot_state' => array_merge($c->bot_state ?? [], ['last_turn_message_id' => $previousMarker])])->save();
            }

            throw $e;
        }

        $this->scheduleMessagesThatArrivedDuringTheTurn($c, $lastId);
    }

    /** A customer message stored while this turn ran gets its own turn (final fix wave I4). */
    private function scheduleMessagesThatArrivedDuringTheTurn(Conversation $c, int $lastId): void
    {
        $c->refresh();

        if ($c->handler !== Handler::Bot) {
            return;
        }

        $newest = $c->messages()->where('direction', MessageDirection::In->value)->where('id', '>', $lastId)->orderByDesc('id')->first();

        if ($newest !== null) {
            app(ReplyScheduler::class)->schedule($newest, $c);
        }
    }
}
