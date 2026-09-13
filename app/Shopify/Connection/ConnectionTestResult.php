<?php

namespace App\Shopify\Connection;

final readonly class ConnectionTestResult
{
    /**
     * @param  array<int, string>  $grantedScopes
     * @param  array<int, string>  $missingScopes
     */
    public function __construct(
        public bool $ok,
        public ?string $shopName,
        public ?string $currency,
        public array $grantedScopes,
        public array $missingScopes,
        public ?string $error,
    ) {}
}
