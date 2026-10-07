<?php

namespace App\Simulator\LoadTest;

use App\Enums\Platform;
use App\Models\Ad;

/**
 * The customers of the production load test (2026-10-07): Le Voile's real problem types in
 * Egyptian Arabic, no emoji. Each scenario has an opener, 2-3 follow-up lines and a thanks line;
 * a chat plays its opener, then — one per agent reply — up to two follow-ups and the thanks
 * (three at most, the last always the thanks). `category` is the handover category the
 * backlog seed hands the chat over with (HandoverContext::kindFor() maps it to the queue kind).
 */
final class Scenarios
{
    /** At most this many follow-ups per chat; the last one is the thanks line. */
    public const MAX_FOLLOWUPS = 3;

    /** @var array<string, array{label: string, category: string, opener: string, followups: list<string>, thanks: string}> */
    private const ALL = [
        'quality_complaint' => [
            'label' => 'شكوى من جودة',
            'category' => 'complaint',
            'opener' => 'السلام عليكم، الفستان اللي وصلني القماش بتاعه خفيف جداً ومش زي الصور خالص',
            'followups' => [
                'ده رقم الأوردر 10482، واستلمته امبارح',
                'الخياطة كمان فاكة من عند الكم، أبعتلك صورة؟',
                'طب أنا عايزة أرجعه ولا ممكن تبدلوه بحاجة أحسن؟',
            ],
            'thanks' => 'تمام، شكراً جداً على اهتمامكم',
        ],
        'return' => [
            'label' => 'مرتجع',
            'category' => 'return',
            'opener' => 'لو سمحتي عايزة أرجع الطقم اللي اشتريته الأسبوع اللي فات',
            'followups' => [
                'رقم الأوردر 10391 ولسه بالتيكيت',
                'المندوب هييجي ياخده إمتى؟ وفلوسي هترجع إزاي؟',
            ],
            'thanks' => 'تمام كده، متشكرة',
        ],
        'size_exchange' => [
            'label' => 'استبدال مقاس',
            'category' => 'exchange',
            'opener' => 'البنطلون جه ضيق عليا، ينفع أبدله بمقاس أكبر؟',
            'followups' => [
                'أنا واخدة لارج وعايزة اكس لارج، رقم الأوردر 10517',
                'هل الاستبدال عليه مصاريف شحن تاني؟',
                'ماشي، ابعتوا المندوب يوم السبت لو ينفع',
            ],
            'thanks' => 'شكراً يا جماعة، تسلموا',
        ],
        'late_order' => [
            'label' => 'أوردر متأخر',
            'category' => 'late_order',
            'opener' => 'أنا طالبة أوردر من خمس أيام ولسه موصلش لحد دلوقتي',
            'followups' => [
                'رقم الأوردر 10455 والعنوان في مدينة نصر',
                'محدش كلمني من شركة الشحن خالص',
                'طب ممكن تأكدولي هيوصل إمتى بالظبط؟',
            ],
            'thanks' => 'ماشي، هستنى، شكراً',
        ],
        'wrong_item' => [
            'label' => 'صنف غلط وصل',
            'category' => 'defect',
            'opener' => 'وصلني بلوزة لونها بيج وأنا طالبة أسود',
            'followups' => [
                'رقم الأوردر 10502، ومفتحتش الكيس غير عشان أتأكد',
                'هو أنا هبعتها إزاي وأستلم الصح؟',
            ],
            'thanks' => 'تمام، متشكرة على سرعة الرد',
        ],
        'price' => [
            'label' => 'سؤال سعر',
            'category' => 'price',
            'opener' => 'بكام الفستان الكشمير اللي نزل امبارح؟',
            'followups' => [
                'وهل في لون كحلي منه؟',
                'السعر ده شامل الشحن ولا الشحن لوحده؟',
            ],
            'thanks' => 'تمام، شكراً هفكر وأرجعلكم',
        ],
        'size_color' => [
            'label' => 'مقاس ولون متاح؟',
            'category' => 'sizes',
            'opener' => 'الجيبة البليسيه متاحة مقاس ميديم لون زيتي؟',
            'followups' => [
                'أنا طولي 165 ووزني 68، ميديم هيبقى مظبوط؟',
                'طب لو مش متاح، إمتى هينزل تاني؟',
                'ممكن تحجزيهالي لحد بكرة؟',
            ],
            'thanks' => 'تسلمي، شكراً جداً',
        ],
        'from_ad' => [
            'label' => 'جاية من إعلان',
            'category' => 'product_question',
            'opener' => 'شفت الإعلان بتاعكم وعايزة أعرف تفاصيل الطقم ده',
            'followups' => [
                'الخامة إيه؟ وبيتغسل عادي في الغسالة؟',
                'عايزة أطلب واحد مقاس سمول، أعمل إيه؟',
            ],
            'thanks' => 'تمام، شكراً، هستنى التأكيد',
        ],
        'payment_link' => [
            'label' => 'دفع ولينك دفع',
            'category' => 'payment',
            'opener' => 'عايزة أدفع أونلاين بدل الكاش، ممكن لينك دفع؟',
            'followups' => [
                'اللينك اللي اتبعت مش بيفتح معايا',
                'دفعت دلوقتي، ممكن تأكدولي إن الفلوس وصلت؟',
            ],
            'thanks' => 'تمام كده، شكراً ليكم',
        ],
        'branch_hours' => [
            'label' => 'مواعيد الفروع',
            'category' => 'branch',
            'opener' => 'الفرع اللي في المعادي بيفتح الساعة كام النهارده؟',
            'followups' => [
                'وهل الفرع ده فيه نفس الموديلات اللي على الصفحة؟',
                'ينفع أستبدل في الفرع حاجة اشتريتها أونلاين؟',
            ],
            'thanks' => 'متشكرة جداً',
        ],
        'track_order' => [
            'label' => 'تتبع أوردر برقم',
            'category' => 'order_status',
            'opener' => 'عايزة أعرف الأوردر بتاعي فين، رقمه 10533',
            'followups' => [
                'هو مكتوب عندي إنه اتشحن من يومين',
                'ممكن رقم المندوب أكلمه؟',
            ],
            'thanks' => 'تمام، شكراً يا قمر',
        ],
        'cancel_order' => [
            'label' => 'إلغاء أوردر',
            'category' => 'cancel_order',
            'opener' => 'لو سمحتي عايزة ألغي الأوردر اللي عملته النهارده الصبح',
            'followups' => [
                'رقمه 10561، وملحقش يتشحن على ما أعتقد',
                'هل ممكن بدل ما ألغيه أغير المقاس بس؟',
            ],
            'thanks' => 'تمام، شكراً على تعاونكم',
        ],
        'discount' => [
            'label' => 'خصم وعرض',
            'category' => 'price',
            'opener' => 'هو العرض بتاع التانية بنص التمن لسه شغال؟',
            'followups' => [
                'وهل ينفع أجمع العرض مع كود الخصم اللي جالي؟',
                'العرض ده على كل الأقسام ولا الفساتين بس؟',
            ],
            'thanks' => 'حلو أوي، شكراً',
        ],
        'shipping_governorate' => [
            'label' => 'شحن لمحافظة',
            'category' => 'shipping',
            'opener' => 'بتشحنوا أسيوط؟ والشحن بكام؟',
            'followups' => [
                'الأوردر بياخد كام يوم لحد ما يوصل؟',
                'ينفع أدفع عند الاستلام ولا لازم مقدم؟',
            ],
            'thanks' => 'تمام، شكراً هطلب النهارده',
        ],
        'slow_reply' => [
            'label' => 'تأخير رد',
            'category' => 'complaint',
            'opener' => 'أنا باعتة من امبارح ومحدش رد عليا، هو في حد هنا؟',
            'followups' => [
                'كنت عايزة أسأل على أوردر رقم 10470 ومحدش بيرد على التليفون كمان',
                'ياريت تشوفولي الموضوع ده بسرعة لو سمحتي',
            ],
            'thanks' => 'ماشي، شكراً إنك رديتي',
        ],
        'gift_wrap' => [
            'label' => 'تغليف هدية',
            'category' => 'product_question',
            'opener' => 'ينفع الأوردر يتغلف هدية ويتبعت لحد تاني؟',
            'followups' => [
                'وهل ممكن مايكونش فيه الفاتورة جوه الكيس؟',
                'عايزاه يوصل يوم الخميس بالظبط',
            ],
            'thanks' => 'تسلموا، شكراً',
        ],
        'change_address' => [
            'label' => 'تغيير عنوان',
            'category' => 'address',
            'opener' => 'عايزة أغير عنوان التوصيل في الأوردر بتاعي',
            'followups' => [
                'رقم الأوردر 10548، والعنوان الجديد في الشيخ زايد',
                'هل ده هيأخر التوصيل؟',
            ],
            'thanks' => 'تمام، متشكرة',
        ],
    ];

    /** @var list<string> */
    private const NAMES = [
        'منى عبد الحميد', 'هبة محمود', 'نورهان السيد', 'سلمى أشرف', 'دينا مصطفى', 'ياسمين عادل', 'رانيا فتحي',
        'شيماء إبراهيم', 'مريم حسن', 'آية جمال', 'إسراء سامي', 'ندى خالد', 'سارة عبد الله', 'فاطمة الزهراء علي',
        'نهى رضا', 'ريهام طارق', 'أميرة صلاح', 'منة الله وائل', 'هاجر محمد', 'بسمة رأفت', 'إيمان شوقي',
        'غادة نبيل', 'رحمة أيمن', 'مي حسام', 'جنى أحمد', 'لبنى عصام', 'داليا ممدوح', 'نسمة عمرو',
    ];

    /** @return array<string, array{label: string, category: string, opener: string, followups: list<string>, thanks: string}> */
    public static function all(): array
    {
        return self::ALL;
    }

    /** @return array{label: string, category: string, opener: string, followups: list<string>, thanks: string}|null */
    public static function get(string $key): ?array
    {
        return self::ALL[$key] ?? null;
    }

    public static function randomKey(): string
    {
        return array_rand(self::ALL);
    }

    /** @return list<string> */
    public static function names(): array
    {
        return self::NAMES;
    }

    public static function randomName(): string
    {
        return self::NAMES[array_rand(self::NAMES)];
    }

    public static function randomPlatform(): Platform
    {
        return LoadTestChannels::PLATFORMS[array_rand(LoadTestChannels::PLATFORMS)];
    }

    /**
     * The lines a chat plays after its opener, one per agent reply: the first follow-ups, then the
     * thanks — MAX_FOLLOWUPS in all.
     *
     * @return list<string>
     */
    public static function followUps(string $key): array
    {
        $s = self::ALL[$key] ?? null;

        if ($s === null) {
            return [];
        }

        return [...array_slice($s['followups'], 0, self::MAX_FOLLOWUPS - 1), $s['thanks']];
    }

    /** Follow-up number $step (1-based), null past the last. */
    public static function followUp(string $key, int $step): ?string
    {
        return self::followUps($key)[$step - 1] ?? null;
    }

    /**
     * A Meta-shaped `referral` for the «جاية من إعلان» scenario: the most recently seen real synced
     * Meta ad, so the conversation is attributed like a real click-to-message chat. Null for any
     * other scenario or when no ad is synced (the chat then simply has no ad).
     *
     * @return array<string, mixed>|null
     */
    public static function referral(string $key): ?array
    {
        if ($key !== 'from_ad') {
            return null;
        }

        $ad = Ad::query()->whereNotNull('external_id')
            ->whereHas('account', fn ($q) => $q->where('platform', 'meta'))
            ->orderByDesc('last_seen_at')->orderByDesc('id')->first();

        if ($ad === null) {
            return null;
        }

        return [
            'source' => 'ADS',
            'type' => 'OPEN_THREAD',
            'ad_id' => (string) $ad->external_id,
            'ads_context_data' => array_filter([
                'ad_title' => $ad->name ?: $ad->headline,
                'photo_url' => $ad->thumbnail_url ?: $ad->image_url,
            ]),
        ];
    }
}
