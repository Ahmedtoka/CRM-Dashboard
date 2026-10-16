<?php

namespace App\Confirmations;

use App\Analytics\ActivityLogger;
use App\Confirmations\Events\OrderConfirmationCalled;
use App\Enums\ActorType;
use App\Enums\OrderConfirmationStatus;
use App\Models\OrderConfirmation;
use App\Models\User;
use InvalidArgumentException;

/**
 * A staff member records how the call to a silent customer went (spec 2026-10-16):
 * confirmed | cancelled | unreachable. Sets the row, writes the Shopify tag, logs
 * the activity and dispatches OrderConfirmationCalled. The UI slice calls this.
 */
final class CallOutcome
{
    public const ACTION = 'order_confirmation.called';

    public function __construct(
        private readonly TagSync $tags,
        private readonly ActivityLogger $activity,
    ) {}

    public static function statusFor(string $outcome): OrderConfirmationStatus
    {
        return match ($outcome) {
            'confirmed' => OrderConfirmationStatus::CalledConfirmed,
            'cancelled' => OrderConfirmationStatus::CalledCancelled,
            'unreachable' => OrderConfirmationStatus::Unreachable,
            default => throw new InvalidArgumentException("Unknown call outcome [{$outcome}]."),
        };
    }

    public function record(OrderConfirmation $confirmation, string $outcome, User $user): OrderConfirmation
    {
        $status = self::statusFor($outcome);

        $confirmation->update([
            'status' => $status,
            'call_outcome' => $outcome,
            'called_by' => $user->id,
            'called_at' => now(),
        ]);

        $this->tags->push($confirmation, $status->shopifyTag());

        rescue(fn () => $this->activity->log(ActorType::User, $user, self::ACTION, $confirmation->order, $confirmation->conversation, [
            'confirmation_id' => $confirmation->id,
            'outcome' => $outcome,
        ]));

        OrderConfirmationCalled::dispatch($confirmation, $outcome);

        return $confirmation->fresh();
    }
}
