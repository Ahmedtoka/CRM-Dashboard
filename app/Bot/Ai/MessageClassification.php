<?php

namespace App\Bot\Ai;

use App\Enums\BotIntent;

final readonly class MessageClassification
{
    public function __construct(
        public BotIntent $intent,
        public string $sentiment, /* positive|neutral|negative */
        public float $confidence,
        public string $model = '',
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $latencyMs = 0,
    ) {}

    /**
     * The handover reason this classification forces (spec §4.1), or null when
     * the bot may answer. Shared by BotEngine and BotPreview so both agree.
     */
    public function handoverReason(float $minConfidence): ?string
    {
        return match (true) {
            $this->confidence < $minConfidence => 'ai_low_confidence',
            $this->intent === BotIntent::Complaint => 'complaint',
            $this->intent === BotIntent::OrderStatus => 'order_status',
            $this->intent->handsOver() => $this->intent->value,
            $this->sentiment === 'negative' => 'negative_sentiment',
            default => null,
        };
    }
}
