<?php

namespace App\Events;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

class ConversationUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public Conversation $conversation) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('inbox')];
    }

    public function broadcastWith(): array
    {
        $c = $this->conversation;
        $customer = $c->customer;
        $preview = $c->messages()->latest('id')->value('body');

        $lockedBy = $c->locked_by_id !== null && $c->locked_until?->isFuture()
            ? User::find($c->locked_by_id)
            : null;

        return [
            'id' => $c->id,
            'status' => $c->status?->value,
            'priority' => $c->priority?->value,
            'handler' => $c->handler?->value,
            'needs_human' => (bool) $c->needs_human,
            'unread_count' => (int) $c->unread_count,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'last_message_preview' => $preview !== null ? Str::limit($preview, 80) : null,
            'platform' => $c->platform?->value,
            'customer' => $customer ? [
                'id' => $customer->id,
                'name' => $customer->name,
                'avatar_url' => $customer->avatar_url,
            ] : null,
            'locked_by' => $lockedBy ? ['id' => $lockedBy->id, 'name' => $lockedBy->name] : null,
        ];
    }
}
