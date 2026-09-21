<?php

namespace App\Bot\Flows\Steps;

use App\Bot\Flows\FlowPrompter;
use App\Bot\Language\KeptNames;
use App\Channels\Cards\OutboundCards;
use App\Models\Branch;
use Illuminate\Support\Collection;

/**
 * The owner's flow 5 (2026-09-19): area buttons, or a typed area or branch name, then
 * the branches as cards (BranchFinder::cards — name, address + phone, «📍 الخريطة» and
 * «📞 اتصل بالفرع»), and the flow goes on (to «تحبي حاجة تانية؟»). A typed branch name
 * sends that branch's card only. An area with more than 10 branches sends the first 10
 * and «فيه فروع تانية، اكتبي اسم الفرع 🌸», and waits here for the name.
 *
 * The message body is the plain-text version of the cards (the platform fallback and
 * what the inbox searches).
 */
final class BranchesListStep extends AreaStep
{
    public const MORE_BRANCHES_TEXT = 'فيه فروع تانية، اكتبي اسم الفرع 🌸';

    protected function forArea(array $state, array $step, string $areaKey): ?StepOutcome
    {
        $branches = $this->finder->branchesOf($areaKey);

        if ($branches->isEmpty()) {
            return null;
        }

        $shown = $branches->take(OutboundCards::MAX_CARDS);
        $message = $this->cardsMessage($shown, 'فروعنا في '.KeptNames::keep((string) $branches->first()->area_ar).' 🌸');

        if ($branches->count() > OutboundCards::MAX_CARDS) {
            return StepOutcome::wait([$message, ['text' => self::MORE_BRANCHES_TEXT, 'buttons' => [FlowPrompter::MAIN_MENU_BUTTON]]], 0);
        }

        return StepOutcome::continue([], [$message]);
    }

    protected function forBranches(array $state, array $step, Collection $branches): ?StepOutcome
    {
        return StepOutcome::continue([], [$this->cardsMessage($branches->take(OutboundCards::MAX_CARDS), null)]);
    }

    /**
     * @param  Collection<int, Branch>  $branches
     * @return array{text:string, cards:array}
     */
    private function cardsMessage(Collection $branches, ?string $heading): array
    {
        $blocks = $branches->map(fn (Branch $b) => $this->finder->branchText($b))->all();

        return [
            'text' => trim(($heading !== null ? $heading."\n\n" : '').implode("\n\n", $blocks)),
            'cards' => $this->finder->cards($branches),
        ];
    }
}
