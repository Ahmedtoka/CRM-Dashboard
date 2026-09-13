<?php

namespace App\Bot\Ai;

use App\Enums\CommentIntent;

final readonly class Classification
{
    public function __construct(
        public CommentIntent $intent,
        public float $confidence,
        public bool $needsHuman,
        public string $model = '',
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $latencyMs = 0,
    ) {}
}
