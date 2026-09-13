<?php

namespace App\Inbox;

use Carbon\CarbonImmutable;

final readonly class WindowState
{
    public const OPEN = 'open';

    public const HUMAN_AGENT = 'human_agent';

    public const TEMPLATE_ONLY = 'template_only';

    public const CLOSED = 'closed';

    public function __construct(
        public string $mode, /* open|human_agent|template_only|closed */
        public ?CarbonImmutable $expiresAt,
    ) {}

    public function canSendText(): bool
    {
        return in_array($this->mode, [self::OPEN, self::HUMAN_AGENT], true);
    }
}
