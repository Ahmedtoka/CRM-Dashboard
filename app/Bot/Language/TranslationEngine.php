<?php

namespace App\Bot\Language;

/** Translates masked Arabic texts into one locale, in one batch. */
interface TranslationEngine
{
    /**
     * @param  list<string>  $texts  masked Arabic sources (TranslationMask)
     * @param  list<bool>  $short  true for a button title (20 characters, Messenger's limit)
     * @return array<int, string> translations by the index of $texts; a missing index means "could not"
     */
    public function translate(array $texts, string $locale, array $short = []): array;
}
