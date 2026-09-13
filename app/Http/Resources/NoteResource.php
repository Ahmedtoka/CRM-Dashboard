<?php

namespace App\Http\Resources;

use App\Models\ConversationNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ConversationNote */
class NoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->user_id !== null ? $this->user : null;

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'body' => $this->body,
            'user' => $user ? ['id' => $user->id, 'name' => $user->name, 'color' => $user->color] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
