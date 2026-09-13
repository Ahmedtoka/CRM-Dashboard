<?php

namespace App\Http\Resources;

use App\Models\Customer;
use App\Models\CustomerIdentity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Customer */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'city' => $this->city,
            'address' => $this->address,
            'avatar_url' => $this->avatar_url,
            'notes' => $this->notes,
            'orders_count' => (int) $this->orders_count,
            'total_spent' => (float) $this->total_spent,
            'last_contact_at' => $this->last_contact_at?->toIso8601String(),
            'identities' => $this->whenLoaded('identities', fn () => $this->identities->map(fn (CustomerIdentity $i) => [
                'id' => $i->id,
                'platform' => $i->platform?->value,
                'external_id' => $i->external_id,
                'username' => $i->username,
                'display_name' => $i->display_name,
                'avatar_url' => $i->avatar_url,
            ])->values()->all()),
            // Resolved to a plain list: Inertia re-wraps nested JsonResource props as {data: [...]}.
            'orders' => $this->whenLoaded('orders', fn () => OrderResource::collection($this->orders)->resolve($request)),
        ];
    }
}
