<?php

namespace App\Channels\Contracts;

use App\Models\Conversation;

/**
 * A platform that caps how many messages the business may send inside its reply window (TikTok, x6:
 * 10 before the customer's first reply, 3 after 48 hours of silence). WindowPolicy asks before every
 * send and closes the window when nothing is left, so the bot and the queue see the same
 * WindowClosedException they already handle.
 */
interface LimitsOutbound
{
    /** Messages the business may still send now, or null when the platform sets no cap at the moment. */
    public function outboundAllowance(Conversation $conversation): ?int;
}
