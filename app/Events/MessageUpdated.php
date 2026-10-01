<?php

namespace App\Events;

use App\Models\Message;
use App\Support\InboxChannels;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class MessageUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public Message $message) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('inbox'),
            ...InboxChannels::forPlatform($this->message->conversation?->platform),
            new PresenceChannel('conversation.'.$this->message->conversation_id),
        ];
    }

    public function broadcastWith(): array
    {
        return MessageCreated::payload($this->message);
    }
}
