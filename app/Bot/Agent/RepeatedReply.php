<?php

namespace App\Bot\Agent;

use App\Bot\ArabicNormalizer;
use App\Bot\Episodes\ConversationEpisode;
use App\Enums\SenderType;
use App\Models\Conversation;

/**
 * The bot going round in circles (staff complaints, round b1, 2026-10-19): a model answer that is
 * word for word one the bot already sent her in the last REPEAT_MINUTES says nothing new — she asked
 * again because it did not help. She is offered a person instead of reading it twice. Short lines
 * («تحت أمرك يا فندم») are not judged.
 */
final class RepeatedReply
{
    public const REPEAT_MINUTES = 30;

    private const MIN_CHARS = 30;

    public static function repeats(Conversation $c, string $reply): bool
    {
        $normalizer = new ArabicNormalizer;
        $mine = self::clean($normalizer->normalize($reply));

        if (mb_strlen($mine) < self::MIN_CHARS) {
            return false;
        }

        return $c->messages()->where('sender_type', SenderType::Bot->value)
            ->where('created_at', '>=', now()->subMinutes(self::REPEAT_MINUTES))
            ->when(ConversationEpisode::historyFloor($c), fn ($q, $floor) => $q->where('id', '>=', $floor))
            ->whereNotNull('body')->latest('id')->limit(10)->pluck('body')
            ->contains(fn ($body) => self::clean($normalizer->normalize((string) $body)) === $mine);
    }

    private static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{N}\s]+/u', '', $text)));
    }
}
