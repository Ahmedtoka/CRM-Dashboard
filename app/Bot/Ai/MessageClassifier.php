<?php

namespace App\Bot\Ai;

/**
 * Classifies a direct customer message for the clothing-store bot (spec
 * §4.3). Kept separate from {@see AiResponder::classify()}, which the comment
 * bot still uses, so existing AiResponder implementations stay valid.
 */
interface MessageClassifier
{
    public function classifyMessage(string $text): MessageClassification;
}
