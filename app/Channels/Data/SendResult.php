<?php

namespace App\Channels\Data;

final readonly class SendResult
{
    public function __construct(
        public bool $success,
        public ?string $externalId = null,
        public ?string $error = null,
        public bool $retryable = false,
        /** The platform rejected our credentials (e.g. Meta OAuthException 190, HTTP 401/403). */
        public bool $authError = false,
    ) {}

    public static function ok(string $externalId): self
    {
        return new self(success: true, externalId: $externalId);
    }

    public static function fail(string $error, bool $retryable = false, bool $authError = false): self
    {
        return new self(success: false, error: $error, retryable: $retryable, authError: $authError);
    }
}
