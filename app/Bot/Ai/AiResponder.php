<?php

namespace App\Bot\Ai;

interface AiResponder
{
    public function classify(string $text): Classification;

    /**
     * @param  array<int, array{role: 'customer'|'agent', text: string}>  $history
     * @param  string[]  $catalogLines
     */
    public function reply(array $history, array $catalogLines, string $systemPrompt): AiReply;
}
