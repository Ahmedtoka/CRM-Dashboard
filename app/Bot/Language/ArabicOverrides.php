<?php

namespace App\Bot\Language;

use App\Models\BotTranslation;

/**
 * The owner's own wording for the sentences written in code (owner, 2026-09-24: «أي كلام
 * بيطلع يكون قدامي»). A step's constant («ممكن اسم حضرتك ورقم الموبايل…») cannot be edited
 * from the dashboard the way a knowledge script can, so «كل ردود البوت» lets her rewrite it,
 * and the rewrite is applied here to every bot text, button and card just before it goes
 * out — before translation, so an English chat translates her wording, not the original.
 *
 * Stored as `bot_translations` rows with locale `ar` (an Arabic "translation" of the
 * Arabic), keyed by the masked source like every translation, so one row covers every
 * order number, price, name and emoji the sentence carries.
 */
final class ArabicOverrides
{
    public const LOCALE = 'ar';

    public const CONTEXT = 'override';

    /** @var array<string, ?string> masked source hash => override text (null: none), for this request */
    private array $memo = [];

    public function __construct(private readonly TranslationMask $mask) {}

    /** The owner's wording for $text, or $text itself. */
    public function apply(string $text): string
    {
        if (trim($text) === '' || ! TranslationMask::hasArabic($text)) {
            return $text;
        }

        [$masked, $values] = $this->mask->mask($text);
        $override = $this->lookup($masked);

        return $override === null ? $text : $this->mask->restore($override, $values);
    }

    /** The override for an already-masked source (the catalog), or null. */
    public function forMasked(string $masked): ?string
    {
        return $this->lookup($masked);
    }

    /**
     * Stores the owner's wording of $original. She writes it as she reads it (with the emoji and the
     * sample values); every value the sentence fills in at run time — a number, a link, a quoted
     * name — must still be in her text and becomes the marker again, while an emoji she changed
     * stays as she typed it. Null when a fill-in value is missing.
     */
    public function save(string $original, string $text): ?BotTranslation
    {
        [$masked, $values] = $this->mask->mask($original);
        $text = trim($text);

        foreach ($values as $i => $value) {
            // An emoji or a symbol carries no data: her own replacement is fine.
            if (preg_match('/[\p{L}\p{N}]/u', $value) !== 1) {
                continue;
            }

            if (! str_contains($text, $value)) {
                return null;
            }

            $text = str_replace($value, TranslationMask::OPEN.$i.TranslationMask::CLOSE, $text);
        }

        return BotTranslation::query()->updateOrCreate(
            ['source_hash' => BotTranslation::hash($masked), 'locale' => self::LOCALE],
            ['source_text' => $masked, 'text' => $text, 'origin' => BotTranslation::ORIGIN_HUMAN, 'context' => self::CONTEXT],
        );
    }

    /** The override as the owner reads it: markers filled back with the original's own values. */
    public function display(string $original): ?string
    {
        [$masked, $values] = $this->mask->mask($original);
        $override = $this->lookup($masked);

        return $override === null ? null : $this->mask->restore($override, $values);
    }

    public function forget(string $original): void
    {
        BotTranslation::query()->where('source_hash', BotTranslation::hash($this->mask->mask($original)[0]))->where('locale', self::LOCALE)->delete();
    }

    private function lookup(string $masked): ?string
    {
        $hash = BotTranslation::hash($masked);

        if (! array_key_exists($hash, $this->memo)) {
            $text = BotTranslation::query()->where('source_hash', $hash)->where('locale', self::LOCALE)->value('text');
            $this->memo[$hash] = is_string($text) && trim($text) !== '' ? $text : null;
        }

        return $this->memo[$hash];
    }
}
