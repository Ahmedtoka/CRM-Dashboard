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
    /** @param  'confirm'|'reminder'|'test'  $kind  test: the confirm template to a phone the owner picks (ConfirmationTestCommand) */
    public function send(OrderConfirmation $confirmation, string $kind = 'confirm'): void;
}
