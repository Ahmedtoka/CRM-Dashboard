<?php

namespace App\Ads\Platforms\Data;

final readonly class AccountInfo
{
    public function __construct(public string $externalId, public string $name, public string $currency, public ?string $timezone, public string $status, public ?float $balance) {}
}
