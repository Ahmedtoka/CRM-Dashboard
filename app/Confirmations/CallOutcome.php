<?php

namespace App\Confirmations;

use App\Confirmations\Events\OrderConfirmationCalled;
use App\Enums\OrderConfirmationStatus;
use App\Models\OrderConfirmation;
use App\Models\User;

/**
 * STUB — slice W3 owns this class (tags, case, notes on the call outcome). Created on wa/c4 only so the
 * call-list endpoint has something to call; on merge keep W3's version. The UI resolves it from the
 * container: app(CallOutcome::class)->record($confirmation, $outcome, $user).
 */
class CallOutcome
{
    public function record(OrderConfirmation $confirmation, string $outcome, User $user): OrderConfirmation
    {
        $confirmation->forceFill([
            'status' => match ($outcome) {
                'confirmed' => OrderConfirmationStatus::CalledConfirmed,
                'cancelled' => OrderConfirmationStatus::CalledCancelled,
                default => OrderConfirmationStatus::Unreachable,
            },
            'call_outcome' => $outcome,
            'called_by' => $user->id,
            'called_at' => now(),
        ])->save();

        OrderConfirmationCalled::dispatch($confirmation, $outcome);

        return $confirmation;
    }
}
