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
 * the activity and dispatches OrderConfirmationCalled. The UI slice calls this; it has
 * no constructor so a test can swap in a subclass.
 */
class CallOutcome
{
    public const ACTION = 'order_confirmation.called';

    /** A call outcome can be recorded from these states (the call list, plus manual calls from the page). */
    public const FROM = [
        OrderConfirmationStatus::NoResponse,
        OrderConfirmationStatus::Sent,
        OrderConfirmationStatus::EditRequested,
        OrderConfirmationStatus::Unreachable,
    ];

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

        // Guarded: of two clicks on the same row only the first one writes, tags and logs.
        $claimed = OrderConfirmation::whereKey($confirmation->id)
            ->whereIn('status', array_map(fn ($s) => $s->value, self::FROM))
            ->update([
                'status' => $status->value,
                'call_outcome' => $outcome,
                'called_by' => $user->id,
                'called_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return $confirmation->fresh() ?? $confirmation;
        }

        $confirmation->refresh();

        app(TagSync::class)->request($confirmation, $status->shopifyTag());

        rescue(fn () => app(ActivityLogger::class)->log(ActorType::User, $user, self::ACTION, $confirmation->order, $confirmation->conversation, [
            'confirmation_id' => $confirmation->id,
            'outcome' => $outcome,
        ]));

        OrderConfirmationCalled::dispatch($confirmation, $outcome);

        return $confirmation->fresh();
    }
}
