<?php

namespace App\Push;

/**
 * A localized push, ready for any driver. `data` values are strings only (an FCM data
 * rule); `channel` is the Android notification channel id; `priority` is high|normal.
 */
final class PushMessage
{
    /**
     * @param  array<string, string>  $data
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly array $data,
        public readonly string $channel = 'general',
        public readonly string $priority = 'normal',
        public readonly ?string $collapseKey = null,
    ) {}

    public function type(): ?string
    {
        return $this->data['type'] ?? null;
    }
}
