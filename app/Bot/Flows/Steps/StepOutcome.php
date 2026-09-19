<?php

namespace App\Bot\Flows\Steps;

/**
 * What a step handler wants the engine to do: send `messages` in order, merge
 * `data` into the flow data (a null value removes the key), then continue to
 * the next step, wait on this one, run the retry path, hand over, or end the
 * flow (the conversation stays with the bot).
 */
final readonly class StepOutcome
{
    public const CONTINUE = 'continue';

    public const WAIT = 'wait';

    public const RETRY = 'retry';

    public const HANDOVER = 'handover';

    public const END = 'end';

    /**
     * @param  list<array{text:string, buttons?:list<array{title:string, payload:string}>}>  $messages
     * @param  array<string, mixed>  $data
     * @param  int|null  $retries  WAIT only: the new retry count (null keeps it)
     */
    public function __construct(
        public string $kind,
        public array $messages = [],
        public array $data = [],
        public ?int $retries = null,
        public ?string $handoverCategory = null,
    ) {}

    public static function continue(array $data = [], array $messages = []): self
    {
        return new self(self::CONTINUE, $messages, $data);
    }

    public static function wait(array $messages = [], ?int $retries = null, array $data = []): self
    {
        return new self(self::WAIT, $messages, $data, $retries);
    }

    public static function retry(): self
    {
        return new self(self::RETRY);
    }

    public static function handover(string $category, array $messages = []): self
    {
        return new self(self::HANDOVER, $messages, handoverCategory: $category);
    }

    public static function end(array $messages = []): self
    {
        return new self(self::END, $messages);
    }
}
