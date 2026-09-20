<?php

namespace App\Bot\Language;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The language a conversation is held in (design 2026-09-21 §1). Read by the
 * outbound translation, written from the customer's own messages:
 *
 *   the first message with letters in it decides;
 *   a later message in the other language switches the conversation, silently;
 *   a message with no letters (digits, an emoji, a photo, a link) changes nothing.
 *
 * The column is only ever written when it actually changes, so this is cheap to
 * call on every turn. Agents' own replies never reach it.
 */
final class ConversationLanguage
{
    public function __construct(private readonly LanguageDetector $detector) {}

    /** The conversation's language, Arabic until she shows us otherwise. */
    public function of(Conversation $c): string
    {
        $language = $c->language;

        return $language === LanguageDetector::EN ? LanguageDetector::EN : LanguageDetector::AR;
    }

    public function isEnglish(Conversation $c): bool
    {
        return $this->of($c) === LanguageDetector::EN;
    }

    /**
     * Reads the burst and follows her. Returns the language in force afterwards.
     *
     * @param  Collection<int, Message>|list<string>  $burst
     */
    public function observe(Conversation $c, Collection|array $burst): string
    {
        $texts = $burst instanceof Collection
            ? $burst->map(fn ($m) => (string) ($m->body ?? ''))->all()
            : array_map('strval', $burst);

        // The last message that says something wins: she switched on purpose.
        foreach (array_reverse($texts) as $text) {
            $detected = $this->detector->detect($text);

            if ($detected !== null) {
                return $this->set($c, $detected);
            }
        }

        return $this->of($c);
    }

    /** Stores $language when it differs from what is stored (nothing is said about the switch). */
    public function set(Conversation $c, string $language): string
    {
        if ($c->language === $language) {
            return $language;
        }

        $c->forceFill(['language' => $language])->save();

        Log::debug('bot.language', ['conversation_id' => $c->id, 'language' => $language]);

        return $language;
    }
}
