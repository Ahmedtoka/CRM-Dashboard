<?php

namespace App\Inbox;

use RuntimeException;

/**
 * Thrown when a message cannot be sent because the platform's reply window
 * does not allow it (see spec §5.6).
 */
class WindowClosedException extends RuntimeException
{
    public function __construct(public readonly string $mode, ?string $message = null)
    {
        parent::__construct($message ?? match ($mode) {
            WindowState::TEMPLATE_ONLY => 'The 24-hour reply window has closed; only an approved template can be sent (window mode: template_only).',
            WindowState::CLOSED => 'The reply window for this conversation is closed; the message cannot be sent (window mode: closed).',
            default => "The message cannot be sent in the current reply window (window mode: {$mode}).",
        });
    }
}
