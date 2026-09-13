<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        if ($user->isSupervisorOrAbove() || (int) $order->created_by_id === (int) $user->id) {
            return true;
        }

        return $order->platform !== null && $user->canAccessPlatform($order->platform);
    }

    /**
     * Cancelling pushes a cancel/restock to Shopify: supervisors and admins only (spec §5.4).
     */
    public function cancel(User $user, Order $order): bool
    {
        return $user->isSupervisorOrAbove();
    }

    /**
     * Re-sending a failed order to Shopify: its creator or a supervisor/admin.
     */
    public function retry(User $user, Order $order): bool
    {
        return $user->isSupervisorOrAbove() || (int) $order->created_by_id === (int) $user->id;
    }

    public function markPaid(User $user, Order $order): bool
    {
        return $user->isSupervisorOrAbove();
    }

    public function ship(User $user, Order $order): bool
    {
        return $user->isSupervisorOrAbove();
    }
}
