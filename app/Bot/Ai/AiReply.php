<?php

namespace App\Bot\Ai;

final readonly class AiReply
{
    /**
     * @param  string  $action  'reply'|'handover'
     */
    public function __construct(
        public string $action,
        public string $text,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $latencyMs = 0,
    ) {}
}
