<?php

namespace App\Inbox;

use App\Enums\Platform;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use Illuminate\Database\UniqueConstraintViolationException;

class CustomerResolver
{
    public function resolve(Platform $p, string $externalId, string $name, ?string $username = null, ?string $avatar = null, ?string $phone = null): CustomerIdentity
    {
        $displayName = trim($name) !== '' ? $name : ($username ?: $externalId);

        $identity = $this->find($p, $externalId);

        if ($identity === null) {
            $customer = Customer::create([
                'name' => $displayName,
                'phone' => $phone ?: null,
                'avatar_url' => $avatar,
            ]);

            try {
                $identity = CustomerIdentity::create([
                    'customer_id' => $customer->id,
                    'platform' => $p,
                    'external_id' => $externalId,
                    'username' => $username,
                    'display_name' => $displayName,
                    'avatar_url' => $avatar,
                ]);
                $identity->setRelation('customer', $customer);

                return $identity;
            } catch (UniqueConstraintViolationException) {
                // Lost a race with a concurrent webhook for the same sender.
                $customer->delete();
                $identity = $this->find($p, $externalId);
            }
        }

        $identity->fill(array_filter([
            'display_name' => trim($name) !== '' ? $name : null,
            'username' => $username,
            'avatar_url' => $avatar,
        ], fn ($v) => $v !== null && $v !== ''));

        if ($identity->isDirty()) {
            $identity->save();
        }

        $customer = $identity->customer;

        if ($phone && blank($customer->phone)) {
            $customer->phone = $phone;
        }
        if ($avatar && blank($customer->avatar_url)) {
            $customer->avatar_url = $avatar;
        }
        if (blank($customer->name)) {
            $customer->name = $displayName;
        }
        if ($customer->isDirty()) {
            $customer->save();
        }

        return $identity;
    }

    private function find(Platform $p, string $externalId): ?CustomerIdentity
    {
        return CustomerIdentity::with('customer')
            ->where('platform', $p)
            ->where('external_id', $externalId)
            ->first();
    }
}
