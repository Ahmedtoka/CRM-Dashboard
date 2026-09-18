<?php

namespace App\Bot\Flows;

/**
 * Last resort when no button, synonym or regex resolved a reply to the
 * waiting flow step (design §3 engine, step 6).
 */
interface FlowAnswerInterpreter
{
    /**
     * @param  array<string, mixed>  $step  the waiting step definition (menu options already filtered)
     * @param  list<array{role:string, text:string}>  $history  recent messages before the reply
     */
    public function interpret(array $step, string $text, array $history): FlowAnswer;
}
