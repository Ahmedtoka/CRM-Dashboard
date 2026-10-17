<?php

namespace App\Shopify\App;

use RuntimeException;

/** The ID token is malformed, signed wrong, expired, for another app or from an unexpected issuer. */
final class InvalidIdToken extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Invalid Shopify ID token ({$reason}).");
    }
}
