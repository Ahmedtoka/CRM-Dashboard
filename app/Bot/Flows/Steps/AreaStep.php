<?php

namespace App\Bot\Flows\Steps;

use App\Bot\Flows\BranchFinder;
use App\Bot\Flows\FlowPrompter;
use App\Models\Branch;
use App\Models\Conversation;
use Illuminate\Support\Collection;

/**
 * Shared by `branch` and `branches_list`: the prompt carries one button per
 * active area (`step:{flow}:{step}:area:<key>`, at most 12 + main menu); an
 * area tap, or a typed place BranchFinder::guess resolves, picks the area.
 * A typed branch name (2026-09-19: "المرغني", "Abbas El Akkad") goes to that
 * branch directly (forBranches) unless the whole reply is an area's own name.
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

    /**
     * What happens when she typed a branch's name; null lets the area rules try.
     *
     * @param  Collection<int, Branch>  $branches  every active branch that name matched
     */
    protected function forBranches(array $state, array $step, Collection $branches): ?StepOutcome
    {
        return null;
    }

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
        if (trim($text) === '') {
            return null;
        }

        if (! $this->finder->isAreaName($text) && ($branches = $this->finder->matchBranches($text))->isNotEmpty()
            && ($outcome = $this->forBranches($state, $step, $branches)) !== null) {
            return $outcome;
        }

        $area = $this->finder->guess($text);

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
