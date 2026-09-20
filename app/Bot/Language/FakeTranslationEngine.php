<?php

namespace App\Bot\Language;

/**
 * Offline engine (tests, and any environment without an Anthropic key). It cannot
 * really translate, so it returns an obviously fake Latin stand-in that keeps the
 * masked markers: real wording comes from the seeded translations
 * (database/seeders/data/bot_translations_en.php), and anything not seeded shows up
 * in a test as «en#…» instead of leaking Arabic to an English customer.
 */
class FakeTranslationEngine implements TranslationEngine
{
    public function translate(array $texts, string $locale, array $short = []): array
    {
        $out = [];

        foreach (array_values($texts) as $i => $text) {
            preg_match_all('~⟦\d+⟧~u', $text, $markers);
            $out[$i] = trim($locale.'#'.substr(md5($text), 0, 6).' '.implode(' ', $markers[0]));
        }

        return $out;
    }
}
