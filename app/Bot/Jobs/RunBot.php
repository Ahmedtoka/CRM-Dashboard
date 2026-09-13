<?php

namespace App\Bot\Jobs;

use App\Bot\BotEngine;
use App\Enums\MessageDirection;
use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class RunBot implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $messageId) {}

    /**
     * One bot decision per conversation at a time: two customer messages arriving
     * together would otherwise produce two overlapping replies/handovers.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $conversationId = Message::whereKey($this->messageId)->value('conversation_id');

        if ($conversationId === null) {
            return [];
        }

        return [(new WithoutOverlapping("conv:{$conversationId}"))->releaseAfter(3)->expireAfter(120)];
    }

    public function handle(BotEngine $engine): void
    {
        $message = Message::find($this->messageId);

        if ($message === null) {
            return;
        }

        // A newer customer message has its own RunBot job queued: answer that one
        // (with the whole history) instead of replying to every message in a burst.
        $hasNewerInbound = Message::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('direction', MessageDirection::In->value)
            ->where('id', '>', $message->id)
            ->exists();

        if ($hasNewerInbound) {
            return;
        }

        $engine->handleInbound($message);
    }
}
