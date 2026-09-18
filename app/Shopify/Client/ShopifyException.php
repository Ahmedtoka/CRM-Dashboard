<?php

namespace App\Shopify\Client;

use RuntimeException;
use Throwable;

/**
 * Typed failure from a ShopifyClient call (spec §2, §4.4).
 *
 * kinds:
 * - 'auth'         401/403 or GraphQL ACCESS_DENIED — integration flagged, no retry.
 * - 'throttled'    429 / THROTTLED exhausted all retries.
 * - 'transport'    network failure or 5xx (queries after all retries; mutations at once, never resent).
 * - 'graphql'      non-throttle GraphQL `errors` (bad query/input) — not retried.
 * - 'user_errors'  mutation returned non-empty userErrors.
 * - 'not_connected' no connected ShopifyIntegration to use.
 */
final class ShopifyException extends RuntimeException
{
    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly array $userErrors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
