<?php

namespace App\Policies;

use App\Enums\Platform;
use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    /**
     * Moderators see customers who have an identity on one of their platforms.
     */
    public function view(User $user, Customer $customer): bool
    {
        if ($user->isSupervisorOrAbove()) {
            return true;
        }

        return $customer->identities()
            ->whereIn('platform', array_map(fn (Platform $p) => $p->value, $user->platforms()))
            ->exists();
    }

    public function merge(User $user, Customer $customer): bool
    {
        return $user->isSupervisorOrAbove();
    }
}
