<?php

namespace App\Bot\Language;

/**
 * Which language the customer is writing in (design 2026-09-21 §1):
 *
 *   Arabic script                        → ar
 *   Latin script that reads as Franco    → ar   («3ayza», «msh», «ana 3ayza arag3»)
 *   Latin script otherwise               → en
 *   No letters at all (digits, emoji, a photo, a link) → null, the conversation keeps
 *   the language it already has.
 *
 * Nothing here is stored; App\Bot\Language\ConversationLanguage decides what to do
 * with the answer.
 */
final class LanguageDetector
{
    public const AR = 'ar';

    public const EN = 'en';

    /**
     * Franco/Arabizi words, lowercased. Only words that are not also English words:
     * «men», «we», «and», «all» and friends are deliberately left out, so an English
     * sentence can never be read as Franco because of one of them.
     */
    private const FRANCO_WORDS = [
        'ana', 'enta', 'enti', 'ezay', 'ezayk', 'ezayek', 'leh', 'fein', 'feen', 'emta', 'ezzay',
        'msh', 'mesh', 'mafish', 'mafeesh', 'mfish', 'kda', 'keda', 'kaman', 'bardo', 'bardu', 'tamam',
        'mmkn', 'momken', 'mumken', 'momkn', 'ayza', 'ayzaa', 'ayez', 'awez', 'awza', 'aiza', 'aez',
        'shokran', 'shukran', 'mersi', 'salam', 'salamo', 'alaikom', 'alaykom', 'sabah', 'masa', 'ahlan',
        'habibti', 'yaani', 'khalas', 'kalas', 'delwaty', 'baden', 'lessa', 'lsa',
        'aiwa', 'aywa', 'ayoa', 'gamda', 'helw', 'helwa', 'kwayes', 'kwais', 'kwayess',
        'orderi', 'talaby', 'mandoob', 'mandob', 'estebdal', 'morgtaa', 'mortagaa', 'fadlek', 'fadlik',
        'law', 'samahti', 'meen', 'eshmalha', 'haga', 'hagat', 'betaa', 'bta3', 'ezaa',
    ];

    /**
     * Digit-for-letter spellings that only appear in Franco: 3 (ع), 7 (ح), 2 (ء), 5 (خ),
     * 9 (ق) used as a letter. Written so that «2nd», «mp3» and «covid19» do not match:
     * the digit must open a word of its own («3ayza»), sit between letters («ba3d») or
     * close a word of at least three letters («mabsoo7»).
     */
    private const FRANCO_DIGIT_PATTERN = '/\b[357][a-z]{2,}|[a-z][234579][a-z]|\b[a-z]{3,}[2357]\b/i';

    /** 'ar' | 'en' | null (nothing to go on). */
    public function detect(string $text): ?string
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        // Links, emails and @handles are written in Latin in both languages: they say nothing.
        $text = (string) preg_replace('~https?://\S+|www\.\S+|\S+@\S+\.\S+~iu', ' ', $text);

        if (preg_match('/\p{Arabic}/u', $text) === 1) {
            return self::AR;
        }

        if (preg_match('/[a-z]/i', $text) !== 1) {
            return null;
        }

        return $this->isFranco($text) ? self::AR : self::EN;
    }

    /** Arabic written in Latin letters: digit-letters, or a Franco word among few enough words. */
    public function isFranco(string $text): bool
    {
        if (preg_match(self::FRANCO_DIGIT_PATTERN, $text) === 1) {
            return true;
        }

        $words = preg_split('/[^a-z0-9]+/i', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return false;
        }

        $franco = 0;

        foreach ($words as $word) {
            if (in_array($word, self::FRANCO_WORDS, true)) {
                $franco++;
            }
        }

        // "el" or "we" alone inside an English sentence must not flip it: at least a
        // quarter of the words (and never a single hit in a long sentence) must be Franco.
        return $franco > 0 && $franco * 4 >= count($words);
    }
}
