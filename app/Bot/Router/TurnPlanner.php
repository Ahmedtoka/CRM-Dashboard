<?php

namespace App\Bot\Router;

/** Turns one burst into a TaskPlan (bot-hybrid.md §3.3). */
interface TurnPlanner
{
    /**
     * @param  list<array{role:'customer'|'agent', text:string}>  $history  the last messages before the burst
     * @param  list<string>  $burst  her messages of this turn
     * @param  array{flow:?string, step:?string, last_question:?string}  $state  where the chat is
     */
    public function plan(array $history, array $burst, array $state): TaskPlan;

    /** The model the plan came from (null for the keyword planner). */
    public function model(): ?string;
}
