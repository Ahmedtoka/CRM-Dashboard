<?php

namespace App\Confirmations\WhatsApp;

use App\Confirmations\Contracts\ConfirmationSender;
use App\Models\OrderConfirmation;
use LogicException;

/** Placeholder: slice W2 implements the WhatsApp template send. */
final class WhatsAppConfirmationSender implements ConfirmationSender
{
    public function send(OrderConfirmation $confirmation, string $kind = 'confirm'): void
    {
        throw new LogicException('WhatsAppConfirmationSender is not implemented yet (slice W2).');
    }
}
