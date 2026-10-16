<?php

namespace App\Enums;

/** Lifecycle of a WhatsApp order confirmation (spec 2026-10-16). */
enum OrderConfirmationStatus: string
{
    case Scheduled = 'scheduled';
    case Sent = 'sent';
    case Confirmed = 'confirmed';
    case EditRequested = 'edit_requested';
    case CancelRequested = 'cancel_requested';
    case NoResponse = 'no_response';
    case Skipped = 'skipped';
    case Failed = 'failed';
    case CalledConfirmed = 'called_confirmed';
    case CalledCancelled = 'called_cancelled';
    case Unreachable = 'unreachable';

    /** Waiting on the customer: a button tap still counts. */
    public function awaitingCustomer(): bool
    {
        return $this === self::Sent || $this === self::NoResponse;
    }

    /** On the staff call list. */
    public function needsCall(): bool
    {
        return $this === self::NoResponse;
    }

    /** Shopify tag that represents this state, if any. */
    public function shopifyTag(): ?string
    {
        return match ($this) {
            self::Confirmed, self::CalledConfirmed => 'wa-confirmed',
            self::CancelRequested, self::CalledCancelled => 'wa-cancel-request',
            self::EditRequested => 'wa-edit-request',
            self::NoResponse => 'wa-no-response',
            self::Unreachable => 'wa-unreachable',
            default => null,
        };
    }
}
