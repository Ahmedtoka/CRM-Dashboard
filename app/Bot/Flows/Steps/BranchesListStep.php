<?php

namespace App\Bot\Flows\Steps;

use App\Bot\Flows\FlowPrompter;

/** Area buttons, then the area's branch list with a main menu button, then the flow goes on (to `end`). */
final class BranchesListStep extends AreaStep
{
    protected function forArea(array $state, array $step, string $areaKey): ?StepOutcome
    {
        $text = $this->finder->listText($areaKey);

        return $text === '' ? null : StepOutcome::continue([], [['text' => $text, 'buttons' => [FlowPrompter::MAIN_MENU_BUTTON]]]);
    }
}
