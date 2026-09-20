<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One Arabic text of the bot and its translation in one locale (design
 * 2026-09-21 §2). `source_text` is the masked form produced by
 * App\Bot\Language\TranslationMask, so «الأوردر #1234» and «الأوردر #9876»
 * share one row.
 */
class BotTranslation extends Model
{
    public const ORIGIN_AUTO = 'auto';

    public const ORIGIN_HUMAN = 'human';

    protected $fillable = ['source_hash', 'source_text', 'locale', 'text', 'origin', 'context'];

    public static function hash(string $maskedSource): string
    {
        return hash('sha256', $maskedSource);
    }

    public function isHuman(): bool
    {
        return $this->origin === self::ORIGIN_HUMAN;
    }
}
