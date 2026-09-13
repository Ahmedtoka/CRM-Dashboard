<?php

namespace App\Events;

use App\Models\Comment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast whenever a comment is ingested or acted on (reply/hide/private
 * reply/bot decision), so the Comments screen updates live (spec §5.5).
 */
class CommentUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public Comment $comment) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('comments')];
    }

    public function broadcastWith(): array
    {
        $c = $this->comment;

        return [
            'id' => $c->id,
            'post_id' => $c->post_id,
            'status' => $c->status?->value,
            'intent' => $c->intent?->value,
            'body' => $c->body,
            'public_reply' => $c->public_reply,
            'replied_by_type' => $c->replied_by_type?->value,
            'private_reply_sent_at' => $c->private_reply_sent_at?->toIso8601String(),
            'conversation_id' => $c->conversation_id,
        ];
    }
}
