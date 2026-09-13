<?php

namespace App\Inbox;

use App\Analytics\ActivityLogger;
use App\Enums\ActorType;
use App\Enums\ConversationStatus;
use App\Events\ConversationUpdated;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\User;
use App\Support\SafeBroadcast;

/**
 * Small human-driven conversation state changes shared by the web and API
 * controllers: resolve, reopen, mark read, internal notes, tags.
 */
class ConversationActions
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function resolve(Conversation $c, User $u): Conversation
    {
        // A resolved conversation has nobody "replying", whoever held the soft lock.
        $c->forceFill([
            'status' => ConversationStatus::Resolved,
            'resolved_at' => now(),
            'resolved_by_id' => $u->id,
            'locked_by_id' => null,
            'locked_until' => null,
        ])->save();

        $this->logger->log(ActorType::User, $u, ActivityLogger::CONVERSATION_RESOLVED, null, $c);

        SafeBroadcast::send(new ConversationUpdated($c));

        return $c;
    }

    public function reopen(Conversation $c, User $u): Conversation
    {
        $c->forceFill([
            'status' => ConversationStatus::Open,
            'resolved_at' => null,
            'resolved_by_id' => null,
        ])->save();

        $this->logger->log(ActorType::User, $u, ActivityLogger::CONVERSATION_REOPENED, null, $c);

        SafeBroadcast::send(new ConversationUpdated($c));

        return $c;
    }

    public function markRead(Conversation $c): Conversation
    {
        if ((int) $c->unread_count !== 0) {
            $c->forceFill(['unread_count' => 0])->save();

            SafeBroadcast::send(new ConversationUpdated($c));
        }

        return $c;
    }

    public function addNote(Conversation $c, User $u, string $body): ConversationNote
    {
        $note = $c->notes()->create(['user_id' => $u->id, 'body' => $body]);
        $note->setRelation('user', $u);

        $this->logger->log(ActorType::User, $u, ActivityLogger::NOTE_ADDED, $note, $c);

        return $note;
    }

    /**
     * @param  array<int, int>  $tagIds
     */
    public function syncTags(Conversation $c, array $tagIds): Conversation
    {
        $c->tags()->sync($tagIds);
        $c->load('tags');

        SafeBroadcast::send(new ConversationUpdated($c));

        return $c;
    }
}
