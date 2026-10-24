<?php

namespace App\Http\Resources;

use App\Http\Support\StoredMessage;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
class MessageResource extends JsonResource
{
    /** Web responses carry Arabic as UTF-8, the mobile API as `\uXXXX` (see ConversationResource::jsonOptionsFor). */
    public function jsonOptions(): int
    {
        return ConversationResource::jsonOptionsFor(request());
    }

    /** The messages page encodes like the single resource (ResourceCollection does not ask the item class). */
    protected static function newCollection($resource): AnonymousResourceCollection
    {
        return new class($resource, static::class) extends AnonymousResourceCollection
        {
            public function jsonOptions(): int
            {
                return ConversationResource::jsonOptionsFor(request());
            }
        };
    }

    public function toArray(Request $request): array
    {
        $user = $this->user_id !== null ? $this->user : null;

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'direction' => $this->direction?->value,
            'sender_type' => $this->sender_type?->value,
            'user' => $user ? ['id' => $user->id, 'name' => $user->name, 'color' => $user->color] : null,
            'body' => $this->body,
            'buttons' => $this->buttons ?? [],
            'cards' => $this->cards,
            'payload' => $this->payload,
            'attachments' => AttachmentResource::collection(
                $this->resource->relationLoaded('mediaAttachments') ? $this->mediaAttachments : $this->mediaAttachments()->get()
            )->resolve($request),
            'status' => $this->status?->value,
            'error' => StoredMessage::error($this->error),
            'is_template' => (bool) $this->is_template,
            // Sent from Meta Business Suite or the phone app, recorded from its echo (2026-10-17).
            'sent_outside_crm' => (bool) $this->sent_outside_crm,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
