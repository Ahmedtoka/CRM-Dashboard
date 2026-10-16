<?php

namespace App\Inbox;

use App\Models\Conversation;
use App\Models\Message;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Handlers that may take a stored inbound customer message before the bot sees it (e.g. a tap on
 * an order-confirmation button, 2026-10-16). Each returns true when it handled the message; the
 * first one that does wins and the bot is not run for that message. A handler that throws is
 * reported and counts as "not handled", so the message still reaches the bot or staff.
 */
final class InboundInterceptors
{
    /** @var list<Closure(Message, Conversation): bool> */
    private array $handlers = [];

    /** @param  Closure(Message, Conversation): bool  $handler */
    public function register(Closure $handler): void
    {
        $this->handlers[] = $handler;
    }

    public function handle(Message $message, Conversation $conversation): bool
    {
        foreach ($this->handlers as $handler) {
            try {
                if ($handler($message, $conversation) === true) {
                    return true;
                }
            } catch (Throwable $e) {
                Log::warning('inbox.interceptor_failed', ['message_id' => $message->id, 'exception' => $e::class]);
                report($e);
            }
        }

        return false;
    }
}
