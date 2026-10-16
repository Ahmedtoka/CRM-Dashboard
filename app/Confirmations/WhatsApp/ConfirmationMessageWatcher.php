<?php

namespace App\Confirmations\WhatsApp;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\OrderConfirmationStatus;
use App\Models\Message;
use App\Models\OrderConfirmation;
use Illuminate\Support\Str;

/**
 * A confirmation template that fails after it was queued (the send job gave up, or WhatsApp
 * reported it undelivered, e.g. the template is not approved) marks its confirmation row:
 * failed for the confirmation itself, last_error only for the reminder.
 */
final class ConfirmationMessageWatcher
{
    public function updated(Message $message): void
    {
        if (! $message->is_template || $message->direction !== MessageDirection::Out
            || $message->status !== MessageStatus::Failed || ! $message->wasChanged('status')) {
            return;
        }

        $error = Str::limit((string) ($message->error ?: 'send_failed'), 250);

        OrderConfirmation::query()->where('outbound_message_id', $message->id)
            ->where('status', OrderConfirmationStatus::Sent->value)
            ->update(['status' => OrderConfirmationStatus::Failed->value, 'sent_at' => null, 'last_error' => $error, 'updated_at' => now()]);

        OrderConfirmation::query()->where('reminder_message_id', $message->id)
            ->update(['last_error' => $error, 'updated_at' => now()]);
    }
}
