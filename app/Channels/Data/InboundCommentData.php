<?php

namespace App\Channels\Data;

use App\Enums\Platform;
use Carbon\CarbonImmutable;

final readonly class InboundCommentData
{
    public function __construct(
        public Platform $platform,
        public string $channelExternalId,
        public string $postExternalId,
        public string $commentExternalId,
        public string $customerExternalId,
        public string $customerName,
        public string $body,
        public CarbonImmutable $occurredAt,
        public ?string $parentExternalId = null,
        public ?string $postCaption = null,
        public ?string $postPermalink = null,
        public bool $isAd = false,
    ) {}
}
