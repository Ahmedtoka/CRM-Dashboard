<?php

namespace App\Bot\Language;

/**
 * The bits of a message that must not be handed to a translator (design 2026-09-21 §2
 * and §4). Two kinds, both registered while the turn builds its texts and read while it
 * is sent:
 *
 *   keep()  a name that stays exactly as it is — a product, a branch, its address, the
 *           customer's own first name. §4 excepts them, and it is also what makes the
 *           translation cache work: «أهلاً يا سارة» and «أهلاً يا منى» become one source.
 *   swap()  a word with a ready translation of its own — the Arabic month names, so
 *           «اتسلم يوم 12 سبتمبر» reads «delivered on 12 September» without a model call
 *           and without twelve near-identical cache entries.
 *
 * The set lives for the turn only: it is a note about the strings being built right now,
 * never stored state.
 */
final class KeptNames
{
    /** @var array<string, array<string, string>> value => [locale => replacement] ([] = keep as it is) */
    private static array $values = [];

    /** Registers a name as untranslatable and returns it unchanged. */
    public static function keep(?string $name): string
    {
        $name = trim((string) $name);

        if ($name !== '' && ! isset(self::$values[$name])) {
            self::$values[$name] = [];
        }

        return $name;
    }

    /** Registers an Arabic word with its ready English, and returns the Arabic. */
    public static function swap(string $arabic, string $english, string $locale = LanguageDetector::EN): string
    {
        $arabic = trim($arabic);

        if ($arabic !== '') {
            self::$values[$arabic][$locale] = $english;
        }

        return $arabic;
    }

    /** This exact text is a registered name: the whole message is left alone. */
    public static function has(string $text): bool
    {
        return isset(self::$values[trim($text)]) && self::$values[trim($text)] === [];
    }

    /**
     * The registered values, longest first, so «فرع مدينة نصر» is matched before «مدينة نصر».
     *
     * @return array<string, array<string, string>>
     */
    public static function all(): array
    {
        $values = self::$values;
        uksort($values, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return $values;
    }

    /** Tests and long-running workers: start each turn with an empty set. */
    public static function reset(): void
    {
        self::$values = [];
    }
}
