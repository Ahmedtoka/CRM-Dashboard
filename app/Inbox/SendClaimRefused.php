<?php

namespace App\Inbox;

use RuntimeException;

/**
 * Thrown inside `OutboundService::sendBot()`'s transaction when its `$claim` refused the send
 * (already sent by an earlier try, or no longer true): the Message is never created.
 */
class SendClaimRefused extends RuntimeException {}
