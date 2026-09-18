<?php

namespace App\Bot\Flows\Steps;

use App\Models\Conversation;
use Illuminate\Support\Collection;

/**
 * A step type with its own entry and answer rules (order, photo, branch,
 * branches_list, status). `$state` is FlowState::flow() of the conversation
 * and `$step` the step definition.
 */
interface FlowStep
{
    /** Runs when the step is entered. */
    public function enter(Conversation $c, array $state, array $step): StepOutcome;

    /**
     * The step's prompt, used on entry, retry and re-prompt.
     *
     * @return array{text:string, buttons:list<array{title:string, payload:string}>}
     */
    public function prompt(array $state, array $step): array;

    /** A typed reply resolved by the step's own rule; null lets the engine ask the interpreter. */
    public function answer(Conversation $c, array $state, array $step, string $text, Collection $burst): ?StepOutcome;

    /** The value of a `step:{flow}:{step}:<value>` tap for this step; null when it does not apply (stale). */
    public function payload(Conversation $c, array $state, array $step, string $value): ?StepOutcome;

    /** A reply neither the rule nor the interpreter resolved (not a question or an exit). */
    public function unresolved(Conversation $c, array $state, array $step, string $text): StepOutcome;
}
