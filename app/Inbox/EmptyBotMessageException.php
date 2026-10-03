<?php

namespace App\Inbox;

/**
 * A bot text that was nothing but emoji is empty once the send gate strips it
 * (spec 2026-10-01 §6), and it carries no buttons or cards either: there is
 * nothing to send. It extends WindowClosedException on purpose — every caller of
 * OutboundService::sendBot already treats that as "this send could not happen,
 * carry on", which is exactly what an empty bot message needs.
 */
class EmptyBotMessageException extends WindowClosedException
{
    public const MODE = 'empty';

    public function __construct()
    {
        parent::__construct(self::MODE, __('errors.inbox.nothing_to_send'));
    }
}
