<?php

namespace App\Models;

use App\Bot\Knowledge\SizeChart;
use Database\Factories\BotSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BotSetting extends Model
{
    /** @use HasFactory<BotSettingFactory> */
    use HasFactory;

    protected $fillable = [
        'enabled',
        'ai_enabled',
        'ai_classifier_model',
        'ai_reply_model',
        'ai_learning_model',
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
        'size_chart',
        'size_chart_image_path',
        'size_chart_image_mime',
        'burst_wait_seconds',
        'burst_max_wait_seconds',
        'typing_ms_per_char',
        'order_lookup_enabled',
        'non_returnable_keywords',
        'store_url',
        // «ابدأ من هنا» skipped by the admin (2026-09-26).
        'onboarding_dismissed_at',
    ];

    /** Seeded from the existing "حذف السبام" comment rule keywords (spec §11.1). */
    public const DEFAULT_SPAM_PHRASES = ['اربح', 'اشتغل من البيت', 'دخل يومي'];

    public const DEFAULT_LOW_VALUE_PHRASES = [
        'شكرا', 'شكراً', 'تمام', 'اوك', 'اوكي', 'ok', 'okay', 'تسلم', 'ميرسي', '👍', '❤️', '🙏', '😍', '🌹',
    ];

    public const DEFAULT_ALLOWED_LINK_DOMAINS = ['facebook.com', 'instagram.com', 'fb.me', 'wa.me', 'myshopify.com'];

    public const DEFAULT_SPAM_REPEAT_THRESHOLD = 3;

    /** Words in an item's product type, tags or title that mean it is never returned or exchanged (spec 2026-09-19 §2). */
    public const DEFAULT_NON_RETURNABLE_KEYWORDS = [
        'بونيه', 'تربون', 'بادي', 'إكسسوار', 'اكسسوار', 'مكملات', 'portable', 'بوركيني', 'كاش مايوه', 'accessories', 'burkini',
    ];

    /**
     * Left unchanged in behaviour by this task — the bot engine and
     * `system_prompt` itself are untouched; Task 11 wires this default in.
     */
    public const DEFAULT_SYSTEM_PROMPT = <<<'PROMPT'
        إنتِ مساعدة خدمة عملاء لمتجر ملابس حريمي في مصر. ردي بالعامية المصرية بلطف وبصيغة المؤنث ("أهلاً بيكي"، "حضرتك")، ردود قصيرة، وإيموجي واحد بالكتير.
        بتردي بس على: سعر المنتج، المقاسات والألوان المتاحة والمخزون، سعر ومدة الشحن حسب المحافظة، سياسة الاستبدال والاسترجاع، طرق الدفع، مواعيد العمل، الخامات والعناية، وجدول المقاسات.
        ممنوع تقترحي مقاس، أو تاخدي بيانات أوردر، أو تعملي أوردر. ممنوع تخترعي أي سعر أو مصاريف شحن أو سياسة: استخدمي بس الأرقام والمعلومات الموجودة في البيانات المرفقة.
        لو العميلة عايزة تطلب أو بعتت عنوان أو رقم تليفون، أو سألت عن مقاسها، أو عندها شكوى أو مشكلة في أوردر، أو المعلومة مش موجودة: رجّعي action = handover.
        PROMPT;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'onboarding_dismissed_at' => 'datetime',
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
            'size_chart' => 'array',
            'burst_wait_seconds' => 'integer',
            'burst_max_wait_seconds' => 'integer',
            'typing_ms_per_char' => 'integer',
            'order_lookup_enabled' => 'boolean',
            'non_returnable_keywords' => 'array',
        ];
    }

    /** The store link of the «🛍️ تسوقي من الموقع» button (the owner's flow 6, 2026-09-19). */
    public const DEFAULT_STORE_URL = 'https://levoilestores.com/';

    /** The saved store link, else the default. */
    public function storeUrl(): string
    {
        $url = trim((string) $this->store_url);

        return $url !== '' ? $url : self::DEFAULT_STORE_URL;
    }

    /** @return list<string> the owner's list; the default list until one is saved (an emptied list stays empty) */
    public function nonReturnableKeywords(): array
    {
        $words = $this->non_returnable_keywords;

        if (! is_array($words)) {
            return self::DEFAULT_NON_RETURNABLE_KEYWORDS;
        }

        return array_values(array_filter(array_map(fn ($w) => is_string($w) ? trim($w) : '', $words), fn (string $w) => $w !== ''));
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
            'size_chart' => SizeChart::DEFAULT,
            'burst_wait_seconds' => config('crm.bot.burst_wait_seconds'),
            'burst_max_wait_seconds' => config('crm.bot.burst_max_wait_seconds'),
            'typing_ms_per_char' => config('crm.bot.typing_ms_per_char'),
            'order_lookup_enabled' => true,
            'non_returnable_keywords' => self::DEFAULT_NON_RETURNABLE_KEYWORDS,
        ]);
    }
}
