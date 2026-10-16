<?php

namespace App\Push;

/** What one send did: delivered to the provider, or failed (and whether the token is dead for good). */
final class PushResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly bool $invalidToken = false,
        public readonly ?string $error = null,
    ) {}

    public static function sent(): self
    {
        return new self(true);
    }

    public static function failed(string $error, bool $invalidToken = false): self
    {
        return new self(false, $invalidToken, $error);
    }
}
