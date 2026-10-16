<?php

namespace App\Confirmations\Contracts;

use App\Models\OrderConfirmation;

/**
 * Sends the confirmation or reminder template on WhatsApp (slice W2). Implementations
 * update the row (status, sent_at / reminded_at, message ids, last_error) themselves
 * and never throw for a delivery failure: they mark the row failed instead.
 */
interface ConfirmationSender
{
    /** @param  'confirm'|'reminder'  $kind */
    public function send(OrderConfirmation $confirmation, string $kind = 'confirm'): void;
}
