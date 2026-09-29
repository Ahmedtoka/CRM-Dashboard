<?php

namespace App\Queue\Data;

/** What the bot knew when it handed the customer to the queue (kept on the entry as `bot_summary`). */
final class HandoverContext
{
    /** @param list<string> $summaryLines */
    public function __construct(
        public readonly string $reason,
        public readonly string $category,
        public readonly string $priority,
        public readonly ?string $topic,
        public readonly array $summaryLines,
        public readonly string $kind = 'unknown',
        public readonly ?string $orderNumber = null,
    ) {}

    /** Map the bot's category to the queue kind used for close defaults. */
    public static function kindFor(string $category): string
    {
        return match (true) {
            in_array($category, ['return', 'exchange', 'return_exchange', 'complaint', 'delivery_followup', 'defect', 'late_order'], true) => 'case',
            in_array($category, ['cancel_order', 'edit_order', 'cancel_edit', 'payment', 'address'], true) => 'problem',
            in_array($category, ['product_question', 'price', 'sizes', 'branch', 'shipping', 'order_status'], true) => 'inquiry',
            default => 'unknown',
        };
    }
}
