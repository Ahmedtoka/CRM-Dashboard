<?php

namespace App\Events;

use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\User;
use App\Support\InboxChannels;
use Illuminate\Broadcasting\Channel;
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
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('inbox'), ...InboxChannels::forPlatform($this->conversation->platform)];
    }

    public function broadcastWith(): array
    {
        $c = $this->conversation;
        $customer = $c->customer;
        $last = $c->messages()->latest('id')->first(['body', 'sender_type']);
        $preview = $last?->body;

        $lockedBy = $c->locked_by_id !== null && $c->locked_until?->isFuture()
            ? User::find($c->locked_by_id)
            : null;

        return [
            'id' => $c->id,
            'status' => $c->status?->value,
            'priority' => $c->priority?->value,
            // Queue badges update live, not only on the next list refresh (human bot flow Task 5).
            'priority_level' => $c->priority_level,
            'queue' => $c->queue,
            'handover_category' => $c->handover_category,
            'handover_category_label' => ConversationResource::categoryLabel($c),
            'handover_topic' => $c->handover_topic,
            'handler' => $c->handler?->value,
            'needs_human' => (bool) $c->needs_human,
            'unread_count' => (int) $c->unread_count,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'last_message_preview' => $preview !== null ? Str::limit($preview, 80) : null,
            'last_message_sender' => $last?->sender_type?->value,
            'platform' => $c->platform?->value,
            'customer' => $customer ? [
                'id' => $customer->id,
                'name' => $customer->name,
                'avatar_url' => $customer->avatar_url,
            ] : null,
            'locked_by' => $lockedBy ? ['id' => $lockedBy->id, 'name' => $lockedBy->name] : null,
            'handling' => ConversationResource::handling($c),
            'assignee' => ConversationResource::assignee($c),
            'queue_entry' => ConversationResource::queueEntry($c),
            // The list row's state badge follows the ticket live (UI overhaul Task 5).
            'queue_state' => ConversationResource::queueState($c),
            'last_responder_id' => $c->last_responder_id,
            'open_case_id' => ConversationResource::openCaseId($c),
        ];
    }
}
