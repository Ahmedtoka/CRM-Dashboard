<?php

namespace App\Bot\Flow;

use App\Models\BotIntent;

/** The router's decision for one turn: what to answer, look up, collect, and whether to hand over. */
final readonly class TurnPlan
{
    /**
     * @param  list<BotIntent>  $answerIntents
     * @param  list<BotIntent>  $lookupIntents
     * @param  array{priority:string, queue:string, category:string, reason:string}|null  $handover
     */
    public function __construct(
        public array $answerIntents,
        public array $lookupIntents,
        public ?BotIntent $collectIntent,
        public ?array $handover,
        public bool $clarify,
    ) {}
}
