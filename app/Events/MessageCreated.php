<?php

namespace App\Events;

use App\Http\Resources\AttachmentResource;
use App\Models\Message;
use Illuminate\Broadcasting\Channel;
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
     * @return array<int, Channel>
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
            'attachments' => AttachmentResource::collection($m->loadMissing('mediaAttachments')->mediaAttachments)->resolve(),
            'status' => $m->status?->value,
            'error' => $m->error,
            'created_at' => $m->created_at?->toIso8601String(),
            // Notification context (ruling 1: ids, name, platform, no tokens/phones/full bodies
            // beyond what the message itself already carries) — Dashboard Experience Task 14.
            'conversation' => self::conversationContext($m),
        ];
    }

    /**
     * `loadMissing` is a no-op when the ingest caller already eager-loaded
     * `conversation.customer` on `$m` (e.g. because it needed them for other
     * work), so this never re-queries what's already in memory; it only
     * queries when nothing was preloaded.
     *
     * @return array{platform: ?string, customer_name: ?string, last_responder_id: ?int, locked_by_id: ?int, handler: ?string, priority: ?string}|null
     */
    private static function conversationContext(Message $m): ?array
    {
        $c = $m->loadMissing('conversation.customer')->conversation;

        if ($c === null) {
            return null;
        }

        return [
            'platform' => $c->platform?->value,
            'customer_name' => $c->customer?->name,
            'last_responder_id' => $c->last_responder_id,
            'locked_by_id' => $c->locked_until?->isFuture() ? $c->locked_by_id : null,
            'handler' => $c->handler?->value,
            'priority' => $c->priority?->value,
        ];
    }
}
