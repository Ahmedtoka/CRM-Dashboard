<?php

namespace App\Queue\Events;

use App\Models\QueueEntry;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A queue entry changed (enqueued, customer wrote, called, closed…). Minimal payload for now;
 * Task 6 expands it with the board resource.
 */
class QueueEntryUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public QueueEntry $entry) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('board')];
    }

    public function broadcastWith(): array
    {
        $e = $this->entry;

        return ['id' => $e->id, 'ticket' => $e->ticket_no, 'status' => $e->status, 'priority' => $e->priority, 'conversation_id' => $e->conversation_id];
    }
}
