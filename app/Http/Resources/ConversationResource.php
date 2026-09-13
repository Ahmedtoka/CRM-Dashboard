<?php

namespace App\Http\Resources;

use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @mixin Conversation */
class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Conversation $c */
        $c = $this->resource;
        $attributes = $c->getAttributes();

        // Filled by ConversationQuery::withListColumns(); fall back to a query for single rows.
        $preview = array_key_exists('last_message_body', $attributes)
            ? $attributes['last_message_body']
            : $c->messages()->orderByDesc('id')->value('body');

        $direction = array_key_exists('last_message_direction', $attributes)
            ? $attributes['last_message_direction']
            : $c->messages()->where('sender_type', '!=', SenderType::System->value)->orderByDesc('id')->value('direction');

        $direction = $direction instanceof MessageDirection ? $direction->value : $direction;

        $lockedBy = $c->locked_by_id !== null && $c->locked_until?->isFuture() ? $c->lockedBy : null;
        $firstResponder = $c->first_responder_id !== null ? $c->firstResponder : null;
        $customer = $c->customer;

        return [
            'id' => $c->id,
            'platform' => $c->platform?->value,
            'status' => $c->status?->value,
            'priority' => $c->priority?->value,
            'handler' => $c->handler?->value,
            'needs_human' => (bool) $c->needs_human,
            'source' => $c->source?->value,
            'unread_count' => (int) $c->unread_count,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'last_customer_message_at' => $c->last_customer_message_at?->toIso8601String(),
            'waiting_since' => $direction === MessageDirection::In->value
                ? $c->last_customer_message_at?->toIso8601String()
                : null,
            'last_message_preview' => $preview !== null ? Str::limit((string) $preview, 80) : null,
            'customer' => $customer ? [
                'id' => $customer->id,
                'name' => $customer->name,
                'avatar_url' => $customer->avatar_url,
            ] : null,
            'locked_by' => $lockedBy ? ['id' => $lockedBy->id, 'name' => $lockedBy->name] : null,
            'first_responder' => $firstResponder ? ['id' => $firstResponder->id, 'name' => $firstResponder->name] : null,
            'tags' => $c->tags->map(fn (Tag $t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color])->values()->all(),
        ];
    }
}
