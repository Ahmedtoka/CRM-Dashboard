<?php

namespace App\Channels\Adapters\TikTok;

use RuntimeException;

/**
 * A non-zero `code` in a Business Messaging API envelope (documented return codes, 2026-10-24):
 * 40105 invalid token · 40100 throttled · 40064 blocked by direct-message rules (the window or the
 * message cap) · 40001 no permission · 40002 parameter · 40007 object missing · 40908 file type ·
 * 51065 system error. The message is TikTok's own text with any token scrubbed.
 */
final class TikTokApiException extends RuntimeException
{
    public const INVALID_TOKEN = 40105;

    public const THROTTLED = 40100;

    public const BLOCKED_BY_RULES = 40064;

    public const NO_PERMISSION = 40001;

    public const SYSTEM_ERROR = 51065;

    public function __construct(public readonly int $apiCode, string $message, public readonly ?string $requestId = null)
    {
        parent::__construct($message);
    }

    public function isAuth(): bool
    {
        return $this->apiCode === self::INVALID_TOKEN;
    }

    public function isRetryable(): bool
    {
        return in_array($this->apiCode, [self::THROTTLED, self::SYSTEM_ERROR], true);
    }

    public function isBlockedByRules(): bool
    {
        return $this->apiCode === self::BLOCKED_BY_RULES;
    }
}
