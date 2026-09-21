<?php

namespace App\Bot\Language;

/**
 * Offline engine (tests, and any environment without an Anthropic key). It cannot
 * really translate, so real wording comes from the seeded translations
 * (database/seeders/data/bot_translations_en.php).
 *
 * In a test, anything not seeded shows up as «en#…», which makes a gap loud instead
 * of silently passing Arabic. Outside tests it returns the source text unchanged, so
 * a real customer whose key is missing or misconfigured reads Arabic — awkward, but
 * readable — rather than «en#abc123».
 */
class FakeTranslationEngine implements TranslationEngine
{
    public function __construct(private readonly ?bool $markGaps = null) {}

    public function translate(array $texts, string $locale, array $short = []): array
    {
        $markGaps = $this->markGaps ?? app()->runningUnitTests();
        $out = [];

        foreach (array_values($texts) as $i => $text) {
            if (! $markGaps) {
                $out[$i] = $text;

                continue;
            }

            preg_match_all('~⟦\d+⟧~u', $text, $markers);
            $out[$i] = trim($locale.'#'.substr(md5($text), 0, 6).' '.implode(' ', $markers[0]));
        }

        return $out;
    }
}
