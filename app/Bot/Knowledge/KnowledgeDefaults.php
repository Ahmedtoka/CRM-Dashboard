<?php

namespace App\Bot\Knowledge;

use App\Bot\Flow\Scripts\LeVoileScripts;

/**
 * Default policy knowledge entries (spec §4.2), seeded once by a guarded data
 * migration. Core keys can be deactivated but never deleted.
 *
 * Le Voile's real policies (2026-09-18), worded from the owner's scripts in
 * docs/bot/levoile-agent-knowledge.md §A.3–A.8 / §B.1. No shipping FEE is written
 * here: the bot quotes the live Shopify rate (App\Bot\Grounding\ShippingFeeAnswer).
 * working_hours_text states no hours because the owner has not given any.
 *
 * The 2026_09_18_500010 data migration replaces LEGACY_SAMPLES with these texts
 * only on rows that are still the untouched sample.
 */
final class KnowledgeDefaults
{
    public const CORE_KEYS = ['exchange_policy', 'return_policy', 'shipping_times', 'payment_methods', 'working_hours_text', 'fabric_care', 'store_intro'];

    /** Excluded from exchange and return (owner script, Arabic is the source). */
    private const NON_RETURNABLE = 'المنتجات القطنية (البونيهات، التربون، الباديهات)، الإكسسوارات، مكملات الحجاب، الإسدالات الـ portable، البوركيني، الكاش مايوه';

    /**
     * The placeholder ("نموذج — عدّله") bodies the first seed shipped with, by key.
     * A row whose body is still one of these AND still flagged is_template was
     * never touched by the owner.
     */
    public const LEGACY_SAMPLES = [
        'store_intro' => 'أهلاً بيكي في متجرنا 🌸 بنبيع ملابس حريمي بتصميمات مختارة، وبنوصل لكل محافظات مصر.',
        'working_hours_text' => 'مواعيدنا يوميًا من 10 ص لحد 10 م.',
        'payment_methods' => 'الدفع كاش عند الاستلام، أو أونلاين بلينك دفع بنبعتهولك.',
        'shipping_times' => 'التوصيل من 2 لـ 4 أيام عمل للقاهرة والجيزة، ومن 3 لـ 6 أيام عمل لباقي المحافظات.',
        'exchange_policy' => 'الاستبدال خلال 14 يوم من الاستلام بشرط إن القطعة متلبستش وبالتيكت، ومصاريف شحن الإرجاع على العميلة إلا لو فيه عيب في القطعة.',
        'return_policy' => 'استرجاع الفلوس بيكون للقطع اللي فيها عيب أو لو وصلك موديل غلط بس.',
        'fabric_care' => 'يُفضل الغسيل على الهادي بمية باردة ومن غير مبيض، والكي على حرارة متوسطة من الوش التاني.',
    ];

    /** @return list<array{key:string,title:string,body:string,sort:int}> */
    public static function entries(): array
    {
        $never = self::NON_RETURNABLE;

        return [
            ['key' => 'store_intro', 'sort' => 10, 'title' => 'تعريف المتجر', 'body' => "أهلاً بيكي في Le Voile Le Voile 🌸 براند ملابس محتشمة للستات: طرح، إسدالات، فساتين وأكتر.\nتقدري تطلبي أونلاين من الويب سايت https://levoilestores.com/ أو تزورينا في فروعنا في مصر."],
            ['key' => 'working_hours_text', 'sort' => 20, 'title' => 'مواعيد العمل', 'body' => "الطلب من الويب سايت متاح 24 ساعة 🌸\nخدمة العملاء بترد على الرسايل بالترتيب في أقرب وقت.\nولمواعيد فرع معين ممكن حضرتك تتصلي بالفرع، وأرقام الفروع موجودة في قائمة الفروع."],
            ['key' => 'payment_methods', 'sort' => 30, 'title' => 'طرق الدفع', 'body' => LeVoileScripts::PAYMENT_TEXT],
            ['key' => 'shipping_times', 'sort' => 40, 'title' => 'مدة التوصيل', 'body' => "الاوردر بيوصل القاهرة / الجيزة / الاسكندريه خلال 3-5 ايام عمل، وباقي المحافظات خلال 5-7 ايام عمل ✨ غير محسوب الاجازات الرسميه والاسبوعيه.\nمصاريف الشحن بتتحسب حسب المحافظة وبتظهر لحضرتك قبل تأكيد الأوردر على الويب سايت.\nالشحن خارج مصر عن طريق الويب سايت بس، وتكلفته حسب وزن الشحنة، ومدة التوصيل من 7 لـ 10 أيام."],
            ['key' => 'exchange_policy', 'sort' => 50, 'title' => 'سياسة الاستبدال', 'body' => "- غير متاح معاينة الأوردر أو فتحه في وجود المندوب، ولا تجزئته.\n- الاستبدال خلال 14 يوم من تاريخ استلام الأوردر، بشرط إن القطعة تكون في حالتها الأصلية اللي وصلت بيها.\n- لو الديفوه من عندنا: بنعمل أوردر استرجاع وبيتم الاستبدال بدون مصاريف شحن.\n- لو المقاس أو الموديل مش مناسب: حضرتك بتتحملي مصاريف الشحن، أو ترجعيها لأقرب فرع ليكي بالريسيت.\n- القطعة اللي عليها خصم متاح استبدالها بس.\n- غير متاح استبدال أو استرجاع: {$never} 🌸\n- القطعة اللي اتشرت من فرع وفيها عيب بترجع لنفس الفرع."],
            ['key' => 'return_policy', 'sort' => 60, 'title' => 'سياسة الاسترجاع', 'body' => "- الاسترجاع خلال 14 يوم من تاريخ استلام الأوردر، والقطعة في حالتها الأصلية اللي وصلت بيها.\n- لو المقاس أو الموديل مش مناسب: مصاريف الشحن على حضرتك، أو ترجعيها لأقرب فرع ليكي بالريسيت.\n- القطعة اللي عليها خصم متاح استبدالها بس، مش استرجاعها.\n- غير متاح استرجاع: {$never}.\n- رجوع الفلوس: بعد ما القطعة ترجعلنا من شركة الشحن، قسم الحسابات بيحوّل المبلغ لحضرتك وبنبعتلك سكرين شوت بالتحويل، وبيوصل حسابك خلال 7 لـ 14 يوم عمل 🌸"],
            ['key' => 'fabric_care', 'sort' => 70, 'title' => 'الخامات والعناية', 'body' => 'طريقة الغسيل بتكون يدوي بمية باردة وبمسحوق خفيف زي الجل، ومن غير فرك أو عصر 🌸 وتفاصيل الخامة والعناية موضحة على الويب سايت في صفحة كل منتج.'],
        ];
    }

    /** @return array<string, string> key => current default body */
    public static function bodies(): array
    {
        return array_column(self::entries(), 'body', 'key');
    }
}
