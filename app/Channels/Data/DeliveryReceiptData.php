<?php

namespace App\Channels\Data;

use App\Enums\MessageStatus;
use App\Enums\Platform;
use Carbon\CarbonImmutable;

final readonly class DeliveryReceiptData
{
    public function __construct(
        public Platform $platform,
        public string $externalMessageId,
        public MessageStatus $status,
        public CarbonImmutable $occurredAt,
    ) {}
}
