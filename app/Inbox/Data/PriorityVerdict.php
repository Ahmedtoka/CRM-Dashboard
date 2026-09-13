<?php

namespace App\Inbox\Data;

use App\Enums\ConversationPriority;

/**
 * The outcome of {@see \App\Inbox\ConversationPriorityClassifier::classify()}.
 * `reason` is one of 'link'|'phrase'|'repeat'|'low_value', or null for a normal verdict.
 */
final readonly class PriorityVerdict
{
    public function __construct(
        public ConversationPriority $priority,
        public ?string $reason = null,
    ) {}
}
