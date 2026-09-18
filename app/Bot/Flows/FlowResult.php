<?php

namespace App\Bot\Flows;

/**
 * What FlowEngine::handle did with a customer burst:
 * - handled: the engine consumed the burst (the caller must not answer it again),
 * - question: customer text the agent should answer before the step is re-prompted
 *   (FlowEngine::repromptCurrent); the step is not advanced,
 * - exited: the customer left the flow (exit word, navigation button, interpreter exit).
 */
final readonly class FlowResult
{
    public function __construct(
        public bool $handled,
        public ?string $question = null,
        public bool $exited = false,
    ) {}
}
