<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BotSetting extends Model
{
    /** @use HasFactory<\Database\Factories\BotSettingFactory> */
    use HasFactory;

    protected $fillable = [
        'enabled',
        'ai_enabled',
        'ai_classifier_model',
        'ai_reply_model',
        'system_prompt',
        'min_confidence',
        'max_bot_turns',
        'handover_keywords',
        'comment_reply_delay_min',
        'comment_reply_delay_max',
        'working_hours',
        'outside_hours_message',
        'spam_phrases',
        'low_value_phrases',
        'allowed_link_domains',
        'spam_repeat_threshold',
    ];

    /** Seeded from the existing "حذف السبام" comment rule keywords (spec §11.1). */
    public const DEFAULT_SPAM_PHRASES = ['اربح', 'اشتغل من البيت', 'دخل يومي'];

    public const DEFAULT_LOW_VALUE_PHRASES = [
        'شكرا', 'شكراً', 'تمام', 'اوك', 'اوكي', 'ok', 'okay', 'تسلم', 'ميرسي', '👍', '❤️', '🙏', '😍', '🌹',
    ];

    public const DEFAULT_ALLOWED_LINK_DOMAINS = ['facebook.com', 'instagram.com', 'fb.me', 'wa.me', 'myshopify.com'];

    public const DEFAULT_SPAM_REPEAT_THRESHOLD = 3;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'ai_enabled' => 'boolean',
            'min_confidence' => 'decimal:2',
            'max_bot_turns' => 'integer',
            'handover_keywords' => 'array',
            'comment_reply_delay_min' => 'integer',
            'comment_reply_delay_max' => 'integer',
            'working_hours' => 'array',
            'spam_phrases' => 'array',
            'low_value_phrases' => 'array',
            'allowed_link_domains' => 'array',
            'spam_repeat_threshold' => 'integer',
        ];
    }

    public static function current(): self
    {
        [$delayMin, $delayMax] = config('crm.comment_bot_delay', [5, 30]);

        return static::firstOrCreate(['id' => 1], [
            'enabled' => true,
            'ai_enabled' => true,
            'ai_classifier_model' => config('crm.anthropic.classifier_model'),
            'ai_reply_model' => config('crm.anthropic.reply_model'),
            'min_confidence' => 0.60,
            'max_bot_turns' => 6,
            'handover_keywords' => ['عايز اكلم حد', 'موظف'],
            'comment_reply_delay_min' => $delayMin,
            'comment_reply_delay_max' => $delayMax,
            'spam_phrases' => self::DEFAULT_SPAM_PHRASES,
            'low_value_phrases' => self::DEFAULT_LOW_VALUE_PHRASES,
            'allowed_link_domains' => self::DEFAULT_ALLOWED_LINK_DOMAINS,
            'spam_repeat_threshold' => self::DEFAULT_SPAM_REPEAT_THRESHOLD,
        ]);
    }
}
