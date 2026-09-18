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
        $minUntil = $now->copy()->addSeconds((int) config('crm.soft_lock_seconds', 45));
        $wasHolder = (int) $c->locked_by_id === (int) $u->id && $c->locked_until?->isFuture();

        // Fix round 1, ruling 1: acquire() never SHORTENS an existing, still-active
        // lock — otherwise the claimer's own typing whisper (which calls acquire()
        // under the hood) would cut a 30-minute claim down to the plain 45s soft
        // lock the moment they started typing a reply. It may still extend it
        // (renewing on activity is fine); it just never moves `locked_until` earlier.
        $until = $wasHolder && $c->locked_until->gt($minUntil) ? $c->locked_until : $minUntil;

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
        // Fix round 1, ruling 1: a message the claimer sends never releases their
        // own active claim — only a lock that either isn't a claim (claimed_until
        // null) or whose claim window has already expired actually clears here.
        $released = Conversation::query()
            ->whereKey($c->getKey())
            ->where('locked_by_id', $u->id)
            ->where(function ($q) {
                $q->whereNull('claimed_until')->orWhere('claimed_until', '<=', now());
            })
            ->update(['locked_by_id' => null, 'locked_until' => null, 'claimed_until' => null]);

        if ($released === 0) {
            return;
        }

        $c->forceFill(['locked_by_id' => null, 'locked_until' => null, 'claimed_until' => null])->syncOriginal();

        if ($broadcast) {
            SafeBroadcast::send(new ConversationUpdated($c));
        }
    }

    /**
     * Explicit "استلام" claim: forces the lock regardless of who currently holds
     * it, for `crm.claim_lock_minutes`. Never blocks anyone else from sending —
     * the open-inbox policy (spec §5.4) is unaffected, this is purely a signal.
     * `claimed_until` (separate from the general-purpose `locked_until`) marks
     * this specific lock as a claim, so it survives the claimer's own typing
     * whisper and outbound sends (see `acquire()`/`release()` above) until it
     * expires or another user claims it out from under them.
     */
    public function claim(Conversation $c, User $u): void
    {
        $until = now()->addMinutes((int) config('crm.claim_lock_minutes', 30));

        Conversation::query()->whereKey($c->getKey())->update(['locked_by_id' => $u->id, 'locked_until' => $until, 'claimed_until' => $until]);
        $c->forceFill(['locked_by_id' => $u->id, 'locked_until' => $until, 'claimed_until' => $until])->syncOriginal();

        SafeBroadcast::send(new ConversationUpdated($c));
    }

    public function holder(Conversation $c): ?User
    {
        if ($c->locked_by_id === null || $c->locked_until === null || ! $c->locked_until->isFuture()) {
            return null;
        }

        return User::find($c->locked_by_id);
    }
}
