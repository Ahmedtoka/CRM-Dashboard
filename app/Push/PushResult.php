<?php

namespace App\Push;

/**
 * What one send did (review 01, I3):
 * - sent: delivered to the provider;
 * - device fault: the token itself was refused — counts towards disabling the device,
 *   and `invalidToken` (UNREGISTERED, …) disables it at once;
 * - transient: auth, connection, 429 or 5xx — the device is left alone and the job retries;
 * - error: anything else (configuration, a payload FCM rejects) — logged only, no retry.
 */
final class PushResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly bool $invalidToken = false,
        public readonly ?string $error = null,
        public readonly bool $deviceFault = false,
        public readonly bool $transient = false,
    ) {}

    public static function sent(): self
    {
        return new self(true);
    }

    /** The provider refused this device's token. */
    public static function deviceFault(string $error, bool $invalidToken = false): self
    {
        return new self(false, $invalidToken, $error, deviceFault: true);
    }

    /** Worth retrying later; never the device's fault. */
    public static function transient(string $error): self
    {
        return new self(false, false, $error, transient: true);
    }

    /** Neither the device's fault nor worth a retry (configuration, payload). */
    public static function failed(string $error): self
    {
        return new self(false, false, $error);
    }
}
