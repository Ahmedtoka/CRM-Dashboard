<?php

namespace App\Inbox;

use App\Channels\Data\InboundMessageData;
use App\Models\Conversation;
use App\Models\Message;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Handlers that take an inbound customer message instead of the bot and the staff queue (e.g. a
 * tap on an order-confirmation button, 2026-10-16). Two steps:
 *  - claim(): read-only, asked before the message is recorded, with the customer's latest thread
 *    on that account. A claimed message lands in that thread as it is (a resolved one is not
 *    reopened), is not counted unread and never reaches the handover queue or the bot.
 *  - the claiming handler then runs after the ingest commits. When it does not handle the message
 *    after all (e.g. a race), the inbox treats it as an ordinary message (unread + bot).
 * A handler that throws is reported and counts as "not claimed" / "not handled".
 */
final class InboundInterceptors
{
    /** @var list<array{claims: Closure(InboundMessageData, Conversation): bool, handle: Closure(Message, Conversation): bool}> */
    private array $handlers = [];

    /**
     * @param  Closure(InboundMessageData, Conversation): bool  $claims
     * @param  Closure(Message, Conversation): bool  $handle
     */
    public function register(Closure $claims, Closure $handle): void
    {
        $this->handlers[] = ['claims' => $claims, 'handle' => $handle];
    }

    /** @return (Closure(Message, Conversation): bool)|null the handler that takes this message */
    public function claim(InboundMessageData $data, Conversation $conversation): ?Closure
    {
        foreach ($this->handlers as $handler) {
            try {
                if (($handler['claims'])($data, $conversation) === true) {
                    return $handler['handle'];
                }
            } catch (Throwable $e) {
                Log::warning('inbox.interceptor_failed', ['conversation_id' => $conversation->id, 'exception' => $e::class]);
                report($e);
            }
        }

        return null;
    }

    /** @param  Closure(Message, Conversation): bool  $handle */
    public function handle(Closure $handle, Message $message, Conversation $conversation): bool
    {
        try {
            return $handle($message, $conversation) === true;
        } catch (Throwable $e) {
            Log::warning('inbox.interceptor_failed', ['message_id' => $message->id, 'exception' => $e::class]);
            report($e);

            return false;
        }
    }
}
