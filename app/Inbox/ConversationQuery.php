<?php

namespace App\Inbox;

use App\Enums\ConversationPriority;
use App\Enums\ConversationSource;
use App\Enums\ConversationStatus;
use App\Enums\Handler;
use App\Enums\MessageDirection;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Inbox list query: platform scoping for moderators plus the list filters
 * (spec §7 Inbox). Shared by the web inbox and API v1.
 */
class ConversationQuery
{
    public const PER_PAGE = 30;

    public const FILTERS = ['waiting', 'needs_human', 'bot', 'mine', 'comment', 'ad', 'spam', 'low_priority'];

    /**
     * Conversations the user may see: all for supervisor+, own platforms for moderators.
     *
     * @return Builder<Conversation>
     */
    public static function visibleTo(User $u): Builder
    {
        return Conversation::query()->when(
            ! $u->isSupervisorOrAbove(),
            fn (Builder $q) => $q->whereIn('conversations.platform', array_map(fn (Platform $p) => $p->value, $u->platforms())),
        );
    }

    /**
     * @param  array{platform?: ?string, status?: ?string, filter?: ?string, q?: ?string}  $f
     */
    public function paginate(User $u, array $f): CursorPaginator
    {
        return $this->build($u, $f)->cursorPaginate(self::PER_PAGE)->withQueryString();
    }

    /**
     * @return Builder<Conversation>
     */
    public function build(User $u, array $f): Builder
    {
        $q = static::withListColumns(static::visibleTo($u));

        if ($platform = Platform::tryFrom((string) ($f['platform'] ?? ''))) {
            $q->where('conversations.platform', $platform->value);
        }

        if ($status = ConversationStatus::tryFrom((string) ($f['status'] ?? ''))) {
            $q->where('conversations.status', $status->value);
        }

        if (($term = trim((string) ($f['q'] ?? ''))) !== '') {
            $q->whereHas('customer', fn (Builder $c) => $c
                ->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%"));
        }

        $filter = $f['filter'] ?? null;

        match ($filter) {
            'waiting' => $this->waiting($q),
            'needs_human' => $q->where('conversations.needs_human', true),
            'bot' => $q->where('conversations.handler', Handler::Bot->value),
            'mine' => $q->where(fn (Builder $w) => $w
                ->where('conversations.first_responder_id', $u->id)
                ->orWhere('conversations.last_responder_id', $u->id)
                ->orWhereHas('participants', fn (Builder $p) => $p->where('user_id', $u->id))),
            'comment' => $q->where('conversations.source', ConversationSource::Comment->value),
            'ad' => $q->where('conversations.source', ConversationSource::Ad->value),
            'spam' => $q->where('conversations.priority', ConversationPriority::Spam->value),
            'low_priority' => $q->where('conversations.priority', ConversationPriority::Low->value),
            default => null,
        };

        // The default inbox (and every filter but "spam") hides spam conversations;
        // "waiting"/"needs_human" additionally exclude low-value ones (spec §11.1).
        if ($filter !== 'spam') {
            $q->where('conversations.priority', '!=', ConversationPriority::Spam->value);
        }
        if (in_array($filter, ['waiting', 'needs_human'], true)) {
            $q->where('conversations.priority', '!=', ConversationPriority::Low->value);
        }

        if ($filter === 'waiting') {
            $q->orderBy('conversations.last_customer_message_at')->orderBy('conversations.id');
        } else {
            $q->orderByDesc('conversations.last_message_at')->orderByDesc('conversations.id');
        }

        return $q;
    }

    /**
     * Adds the eager loads and preview/direction sub-selects ConversationResource uses,
     * so listing does not issue per-row queries.
     *
     * @param  Builder<Conversation>  $q
     * @return Builder<Conversation>
     */
    public static function withListColumns(Builder $q): Builder
    {
        return $q->select('conversations.*')
            ->addSelect([
                'last_message_body' => Message::query()->select('body')
                    ->whereColumn('messages.conversation_id', 'conversations.id')
                    ->orderByDesc('id')->limit(1),
                'last_message_direction' => Message::query()->select('direction')
                    ->whereColumn('messages.conversation_id', 'conversations.id')
                    ->where('sender_type', '!=', SenderType::System->value)
                    ->orderByDesc('id')->limit(1),
            ])
            ->with(['customer', 'lockedBy', 'firstResponder', 'tags']);
    }

    /**
     * Open conversations whose latest customer message has no human/bot reply after it.
     */
    private function waiting(Builder $q): void
    {
        $q->where('conversations.status', '!=', ConversationStatus::Resolved->value)
            ->whereNotNull('conversations.last_customer_message_at')
            ->whereNotExists(fn ($s) => $s->selectRaw('1')
                ->from('messages')
                ->whereColumn('messages.conversation_id', 'conversations.id')
                ->where('messages.direction', MessageDirection::Out->value)
                ->whereIn('messages.sender_type', [SenderType::User->value, SenderType::Bot->value])
                ->whereColumn('messages.created_at', '>', 'conversations.last_customer_message_at'));
    }
}
