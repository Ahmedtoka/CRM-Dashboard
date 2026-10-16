<?php

namespace App\Policies;

use App\Models\OrderConfirmation;
use App\Models\User;

class OrderConfirmationPolicy
{
    /** Whoever may see the order may see its confirmation (OrderPolicy::view). */
    public function view(User $user, OrderConfirmation $confirmation): bool
    {
        return $confirmation->order !== null && $user->can('view', $confirmation->order);
    }

    /** The call list (spec 2026-10-16): the staff who can see the order make the call and record it. */
    public function recordCall(User $user, OrderConfirmation $confirmation): bool
    {
        return $this->view($user, $confirmation);
    }
}
