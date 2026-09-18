<?php

namespace App\Channels\Integrations;

use App\Channels\Adapters\MetaGraphClient;
use RuntimeException;

/**
 * A connect/validate step that failed in a way the admin can act on. `code` is a
 * stable key the Integrations page translates (settings.integrations.errors.*);
 * `detail` is Meta's own message (already redacted), shown underneath in LTR.
 */
final class IntegrationException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly ?string $detail = null)
    {
        parent::__construct($errorCode.($detail !== null ? ': '.$detail : ''));
    }

    public static function graph(string $code, mixed $detail): self
    {
        return new self($code, is_string($detail) && $detail !== '' ? MetaGraphClient::redact($detail) : null);
    }

    /**
     * @return array{ok: false, error: string, detail: ?string}
     */
    public function toArray(): array
    {
        return ['ok' => false, 'error' => $this->errorCode, 'detail' => $this->detail];
    }
}
