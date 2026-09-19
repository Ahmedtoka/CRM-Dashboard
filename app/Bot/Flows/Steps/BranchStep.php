<?php

namespace App\Bot\Flows\Steps;

use App\Bot\Flows\FlowPrompter;
use App\Models\Branch;
use App\Models\Conversation;
use Illuminate\Support\Collection;

/**
 * Area buttons → that area's branch buttons (`step:{flow}:{step}:branch:<id>`) → saves `branch_id` / `branch_name`.
 * A typed branch name (2026-09-19) is taken directly; a name several branches share lists just those.
 */
final class BranchStep extends AreaStep
{
    public const CHOOSE_BRANCH_TEXT = 'أنهي فرع بالظبط؟ 👇';

    public function payload(Conversation $c, array $state, array $step, string $value): ?StepOutcome
    {
        if (! str_starts_with($value, 'branch:')) {
            return parent::payload($c, $state, $step, $value);
        }

        $id = substr($value, 7);
        $branch = ctype_digit($id) ? Branch::query()->where('is_active', true)->find((int) $id) : null;

        return $branch === null ? null : $this->picked($branch);
    }

    protected function forBranches(array $state, array $step, Collection $branches): ?StepOutcome
    {
        return $branches->count() === 1 ? $this->picked($branches->first()) : $this->choose($state, $branches->take(self::MAX_BUTTONS));
    }

    protected function forArea(array $state, array $step, string $areaKey): ?StepOutcome
    {
        $branches = Branch::query()->where('area_key', $areaKey)->where('is_active', true)
            ->orderBy('sort')->orderBy('id')->limit(self::MAX_BUTTONS)->get(['id', 'name']);

        return $branches->isEmpty() ? null : $this->choose($state, $branches);
    }

    private function picked(Branch $branch): StepOutcome
    {
        return StepOutcome::continue(['branch_id' => $branch->id, 'branch_name' => $branch->name]);
    }

    /** @param  Collection<int, Branch>  $branches */
    private function choose(array $state, Collection $branches): StepOutcome
    {
        $buttons = $branches->map(fn (Branch $b) => self::button((string) $b->name, "step:{$state['key']}:{$state['step']}:branch:{$b->id}"))->values()->all();
        $buttons[] = FlowPrompter::MAIN_MENU_BUTTON;

        return StepOutcome::wait([['text' => self::CHOOSE_BRANCH_TEXT, 'buttons' => $buttons]]);
    }
}
