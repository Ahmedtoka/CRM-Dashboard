<?php

namespace App\Bot\Learning;

/**
 * The single model call behind `bot:learn` (design §6, learning v2 §2). It
 * reads one day of per-conversation notes, grouped by kind, plus the current
 * catalog and answers with an Arabic summary, the day's stats and at most 15
 * concrete suggestions. It never writes anything: the command validates and
 * stores, the owner approves.
 */
interface LearningAnalyst
{
    /**
     * @param  array<string, list<array{conversation_id:int, summary:string, quote:string, agent_answer?:string}>>  $notes  keyed by kind
     * @param  array{scripts: array<string, string>, intents: array<string, list<string>>, flows: array<string, array<string, array{text: ?string, options: list<string>}>>}  $catalog
     * @return array{summary:string, stats:array, suggestions:list<array>, model:string, input_tokens:int, output_tokens:int}
     */
    public function analyze(array $notes, array $catalog): array;
}
