<?php

namespace App\Inbox;

use App\Events\ConversationUpdated;
use App\Models\Conversation;
use App\Models\User;
use App\Support\SafeBroadcast;

/**
 * "X is replying" soft lock (spec §5.4). Advisory only: others may still send.
 */
class SoftLock
{
    public function acquire(Conversation $c, User $u): bool
    {
        $now = now();
        $until = $now->copy()->addSeconds((int) config('crm.soft_lock_seconds', 45));
        $wasHolder = (int) $c->locked_by_id === (int) $u->id && $c->locked_until?->isFuture();

        $updated = Conversation::query()
            ->whereKey($c->getKey())
            ->where(function ($q) use ($u, $now) {
                $q->whereNull('locked_by_id')
                    ->orWhere('locked_by_id', $u->id)
                    ->orWhereNull('locked_until')
                    ->orWhere('locked_until', '<=', $now);
            })
            ->update(['locked_by_id' => $u->id, 'locked_until' => $until]);

        if ($updated === 0) {
            return false;
        }

        $c->forceFill(['locked_by_id' => $u->id, 'locked_until' => $until])->syncOriginal();

        if (! $wasHolder) {
            SafeBroadcast::send(new ConversationUpdated($c));
        }

        return true;
    }

    public function release(Conversation $c, User $u, bool $broadcast = true): void
    {
        $released = Conversation::query()
            ->whereKey($c->getKey())
            ->where('locked_by_id', $u->id)
            ->update(['locked_by_id' => null, 'locked_until' => null]);

        if ($released === 0) {
            return;
        }

        $c->forceFill(['locked_by_id' => null, 'locked_until' => null])->syncOriginal();

        if ($broadcast) {
            SafeBroadcast::send(new ConversationUpdated($c));
        }
    }

    public function holder(Conversation $c): ?User
    {
        if ($c->locked_by_id === null || $c->locked_until === null || ! $c->locked_until->isFuture()) {
            return null;
        }

        return User::find($c->locked_by_id);
    }
}
