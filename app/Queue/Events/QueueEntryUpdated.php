<?php

namespace App\Queue\Events;

use App\Http\Resources\QueueEntryResource;
use App\Models\QueueEntry;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A queue entry changed (enqueued, customer wrote, called, closed…): the board's ticket row, and
 * the assignee's own `user.{id}` channel (her inbox strip) while the entry has one.
 */
class QueueEntryUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public QueueEntry $entry) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('board')];

        if ($this->entry->assigned_user_id !== null) {
            $channels[] = new PrivateChannel('user.'.$this->entry->assigned_user_id);
        }

        return $channels;
    }

    public function broadcastWith(): array
    {
        return (new QueueEntryResource($this->entry->loadMissing('conversation.customer')))->resolve();
    }
}
