<?php

namespace App\Channels\Data;

use App\Enums\Platform;
use Carbon\CarbonImmutable;

/**
 * A message the page itself sent, mirrored back on the Messenger/Instagram webhook
 * (`message.is_echo`, 2026-10-17). Either the CRM sent it (already recorded — skipped by
 * App\Inbox\EchoIngestor) or a person replied from Meta Business Suite or the phone app,
 * which is recorded as a staff message "sent outside the CRM".
 *
 * WhatsApp Cloud API has no echoes: replies sent from the WhatsApp Business app never reach
 * the webhook, so they cannot be shown.
 */
final readonly class OutboundEchoData
{
    public function __construct(
        public Platform $platform,
        public string $channelExternalId,
        /** The customer: the echo's `recipient` (its `sender` is the page / IG account). */
        public string $customerExternalId,
        public string $externalMessageId,
        public string $body,
        public CarbonImmutable $occurredAt,
        public array $attachments = [],
        /** The app that sent it (`message.app_id`, Messenger only); ours means the CRM sent it. */
        public ?string $appId = null,
    ) {}
}
