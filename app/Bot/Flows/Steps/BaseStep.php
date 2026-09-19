<?php

namespace App\Bot\Flows\Steps;

use App\Bot\Flows\FlowPrompter;
use App\Bot\Flows\FlowScripts;
use App\Models\Conversation;
use Illuminate\Support\Collection;

/** Defaults: send the prompt and wait, no own answers, unresolved → the engine's retry path. */
abstract class BaseStep implements FlowStep
{
    /** Meta quick-reply title limit. */
    protected const TITLE_MAX = 20;

    public function __construct(protected readonly FlowPrompter $prompter) {}

    public function enter(Conversation $c, array $state, array $step): StepOutcome
    {
        return StepOutcome::wait([$this->prompt($state, $step)]);
    }

    public function prompt(array $state, array $step): array
    {
        return ['text' => $this->prompter->renderText((string) ($step['text'] ?? ''), $state['data'] ?? []), 'buttons' => []];
    }

    public function answer(Conversation $c, array $state, array $step, string $text, Collection $burst): ?StepOutcome
    {
        return null;
    }

    public function payload(Conversation $c, array $state, array $step, string $value): ?StepOutcome
    {
        return null;
    }

    public function unresolved(Conversation $c, array $state, array $step, string $text): StepOutcome
    {
        return StepOutcome::retry();
    }

    /** An active knowledge script, or its seeded text when the owner turned it off. */
    protected function scriptText(string $key): string
    {
        return $this->prompter->script($key) ?? (string) (FlowScripts::all()[$key]['body'] ?? '');
    }

    /** @return array{title:string, payload:string} */
    protected static function button(string $title, string $payload): array
    {
        return ['title' => mb_substr(trim($title), 0, self::TITLE_MAX), 'payload' => $payload];
    }
}
