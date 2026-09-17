<?php

namespace App\Channels\Data;

use App\Enums\Platform;
use Carbon\CarbonImmutable;

final readonly class InboundMessageData
{
    public function __construct(
        public Platform $platform,
        public string $channelExternalId,
        public string $customerExternalId,
        public string $customerName,
        public string $externalMessageId,
        public string $body,
        public CarbonImmutable $occurredAt,
        public array $attachments = [],
        public ?string $customerUsername = null,
        public ?string $customerAvatar = null,
        public ?string $customerPhone = null,
        public ?string $payload = null,
    ) {}
}
