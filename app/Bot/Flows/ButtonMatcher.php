<?php

namespace App\Bot\Flows;

use App\Bot\ArabicNormalizer;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\Message;

/**
 * Resolves an inbound customer message to the payload of a button it answers:
 * a native quick-reply/postback tap (the message's own `payload`), or a typed
 * "2" / exact title match against the latest bot message that offered buttons.
 */
final class ButtonMatcher
{
    public function __construct(private readonly ArabicNormalizer $normalizer) {}

    public function match(Conversation $c, Message $inbound): ?string
    {
        if (filled($inbound->payload)) {
            return (string) $inbound->payload;
        }

        $buttons = (array) ($c->messages()->where('direction', MessageDirection::Out->value)->where('sender_type', SenderType::Bot->value)
            ->where('id', '<', $inbound->id)->whereNotNull('buttons')->latest('id')->value('buttons') ?? []);
        if (is_string($buttons)) {
            $buttons = json_decode($buttons, true) ?: [];
        }
        if ($buttons === []) {
            return null;
        }

        $text = trim($this->normalizer->normalize($this->normalizer->digitsToLatin((string) $inbound->body)));
        $text = trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', '', $text));

        if (preg_match('/^\d{1,2}$/', $text) && isset($buttons[(int) $text - 1])) {
            return (string) $buttons[(int) $text - 1]['payload'];
        }

        foreach ($buttons as $b) {
            $title = trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', '', $this->normalizer->normalize((string) $b['title'])));
            if ($title !== '' && $title === $text) {
                return (string) $b['payload'];
            }
        }

        return null;
    }
}
