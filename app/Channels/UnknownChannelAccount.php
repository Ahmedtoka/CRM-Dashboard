<?php

namespace App\Channels;

use App\Enums\Platform;
use RuntimeException;

/**
 * An inbound message or comment for a page / Instagram account / WhatsApp number we
 * have no connected account for (audit A3). The event is dropped, never routed to
 * another account; ProcessWebhookEvent marks the webhook event 'ignored'.
 */
class UnknownChannelAccount extends RuntimeException
{
    public function __construct(public readonly Platform $platform, public readonly string $channelExternalId)
    {
        parent::__construct("No connected {$platform->value} account for {$channelExternalId}");
    }
}
