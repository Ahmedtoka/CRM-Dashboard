<?php

namespace App\Bot\Flows\Steps;

use App\Bot\Flows\BranchFinder;
use App\Bot\Flows\FlowPrompter;
use App\Models\Conversation;
use Illuminate\Support\Collection;

/**
 * Shared by `branch` and `branches_list`: the prompt carries one button per
 * active area (`step:{flow}:{step}:area:<key>`, at most 12 + main menu); an
 * area tap, or a typed place BranchFinder::guess resolves, picks the area.
 */
abstract class AreaStep extends BaseStep
{
    protected const MAX_BUTTONS = 12;

    public function __construct(FlowPrompter $prompter, protected readonly BranchFinder $finder)
    {
        parent::__construct($prompter);
    }

    /** What happens once the area is known; null when the area has nothing to show. */
    abstract protected function forArea(array $state, array $step, string $areaKey): ?StepOutcome;

    public function prompt(array $state, array $step): array
    {
        $buttons = array_map(
            fn (array $a) => self::button($a['label'], "step:{$state['key']}:{$state['step']}:area:{$a['key']}"),
            array_slice($this->finder->areas(), 0, self::MAX_BUTTONS),
        );
        $buttons[] = FlowPrompter::MAIN_MENU_BUTTON;

        return ['text' => (string) ($step['text'] ?? ''), 'buttons' => $buttons];
    }

    public function answer(Conversation $c, array $state, array $step, string $text, Collection $burst): ?StepOutcome
    {
        $area = trim($text) !== '' ? $this->finder->guess($text) : null;

        return $area !== null ? $this->forArea($state, $step, $area) : null;
    }

    public function payload(Conversation $c, array $state, array $step, string $value): ?StepOutcome
    {
        if (! str_starts_with($value, 'area:')) {
            return null;
        }

        $key = substr($value, 5);

        return in_array($key, array_column($this->finder->areas(), 'key'), true) ? $this->forArea($state, $step, $key) : null;
    }
}
