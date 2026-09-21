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
            WindowState::TEMPLATE_ONLY => __('errors.inbox.window_template_only'),
            WindowState::CLOSED => __('errors.inbox.window_closed'),
            default => __('errors.inbox.window_other', ['mode' => $mode]),
        });
    }
}
