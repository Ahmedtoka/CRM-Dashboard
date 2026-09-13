<?php

namespace App\Http\Resources;

use App\Enums\Platform;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role?->value,
            'color' => $this->color,
            'locale' => $this->locale,
            'is_active' => (bool) $this->is_active,
            'platforms' => array_map(fn (Platform $p) => $p->value, $this->resource->platforms()),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
        ];
    }
}
