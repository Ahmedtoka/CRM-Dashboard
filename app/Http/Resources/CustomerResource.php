<?php

namespace App\Http\Resources;

use App\Models\Customer;
use App\Models\CustomerAddress;
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
            'tags' => $this->tags ?? [],
            'orders_count' => (int) $this->orders_count,
            'total_spent' => (float) $this->total_spent,
            'shopify_orders_count' => (int) $this->shopify_orders_count,
            'shopify_total_spent' => (float) $this->shopify_total_spent,
            'badges' => $this->badges(),
            'flags' => [
                'is_repeat' => (bool) $this->is_repeat,
                'has_open_order' => (bool) $this->has_open_order,
                'has_return' => (bool) $this->has_return,
                'has_stuck_order' => (bool) $this->has_stuck_order,
            ],
            'last_contact_at' => $this->last_contact_at?->toIso8601String(),
            'identities' => $this->whenLoaded('identities', fn () => $this->identities->map(fn (CustomerIdentity $i) => [
                'id' => $i->id,
                'platform' => $i->platform?->value,
                'external_id' => $i->external_id,
                'username' => $i->username,
                'display_name' => $i->display_name,
                'avatar_url' => $i->avatar_url,
            ])->values()->all()),
            'addresses' => $this->whenLoaded('addresses', fn () => $this->addresses->map(fn (CustomerAddress $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'phone' => $a->phone,
                'address1' => $a->address1,
                'address2' => $a->address2,
                'city' => $a->city,
                'province' => $a->province,
                'province_code' => $a->province_code,
                'zip' => $a->zip,
                'is_default' => (bool) $a->is_default,
            ])->values()->all()),
            // Resolved to a plain list: Inertia re-wraps nested JsonResource props as {data: [...]}.
            'orders' => $this->whenLoaded('orders', fn () => OrderResource::collection($this->orders)->resolve($request)),
        ];
    }

    /**
     * `'new'` (ordered before, never a repeat), `'repeat'` (>=2 orders) and/or
     * `'has_return'`, per the customer order flags (spec §11.2).
     *
     * @return list<string>
     */
    private function badges(): array
    {
        $everOrdered = ((int) $this->orders_count) > 0 || ((int) $this->shopify_orders_count) > 0;

        return array_values(array_filter([
            $this->is_repeat ? 'repeat' : ($everOrdered ? 'new' : null),
            $this->has_return ? 'has_return' : null,
        ]));
    }
}
