<?php

namespace App\Http\Resources;

use App\Http\Support\StoredMessage;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
class MessageResource extends JsonResource
{
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
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
