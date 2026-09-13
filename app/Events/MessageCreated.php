<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class MessageCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public Message $message) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('inbox'),
            new PresenceChannel('conversation.'.$this->message->conversation_id),
        ];
    }

    public function broadcastWith(): array
    {
        return self::payload($this->message);
    }

    /**
     * MessageResource-shaped array shared by MessageCreated and MessageUpdated.
     */
    public static function payload(Message $m): array
    {
        $user = $m->user_id !== null ? $m->user : null;

        return [
            'id' => $m->id,
            'conversation_id' => $m->conversation_id,
            'direction' => $m->direction?->value,
            'sender_type' => $m->sender_type?->value,
            'user' => $user ? ['id' => $user->id, 'name' => $user->name, 'color' => $user->color] : null,
            'body' => $m->body,
            'attachments' => $m->attachments ?? [],
            'status' => $m->status?->value,
            'error' => $m->error,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
