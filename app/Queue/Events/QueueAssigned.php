<?php

namespace App\Queue\Events;

use App\Models\QueueEntry;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** «وصلتك #N»: a window was given to this moderator (her inbox opens the conversation). */
class QueueAssigned implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public int $userId, public QueueEntry $entry) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->userId)];
    }

    public function broadcastWith(): array
    {
        $e = $this->entry;

        return [
            'entry_id' => $e->id,
            'conversation_id' => $e->conversation_id,
            'ticket' => $e->ticket_no,
            'window_no' => $e->window_no,
            'bot_summary' => $e->bot_summary,
        ];
    }
}
