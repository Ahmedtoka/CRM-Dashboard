<?php

namespace App\Bot\Flows;

/** The interpreter's reading of a free-text reply to the waiting flow step. */
final readonly class FlowAnswer
{
    public const KINDS = ['answer', 'question', 'exit', 'unknown'];

    /** @param  'answer'|'question'|'exit'|'unknown'  $kind */
    public function __construct(
        public string $kind,
        public ?string $value = null,
    ) {}

    public static function unknown(): self
    {
        return new self('unknown');
    }
}
