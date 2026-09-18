<?php

namespace App\Bot\Learning;

/**
 * The per-conversation review behind learning v2 §2: one small model call
 * over one finished conversation that writes at most 5 short notes about
 * what the bot should learn from it. It never writes anything itself —
 * `ConversationReview` stores the notes and the nightly report reads them.
 */
interface ConversationReviewer
{
    /**
     * @param  array{conversation_id:int, lines: list<string>, intents: list<string>, flows: list<string>}  $transcript
     * @return array{notes: list<array{kind:string, summary:string, quote:string, agent_answer?:string}>, model:string, input_tokens:int, output_tokens:int}
     */
    public function review(array $transcript): array;
}
