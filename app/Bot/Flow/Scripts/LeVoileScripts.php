<?php

namespace App\Bot\Flow\Scripts;

use App\Bot\ArabicNormalizer;

/**
 * Le Voile owner scripts and intent catalog (docs/bot/levoile-reference.md).
 * Seeded once by a guarded data migration; the owner edits them afterwards
 * in settings, so this file is never read at runtime.
 *
 * Script bodies are copied VERBATIM from §1 of the reference (<br> → "\n",
 * owner spelling and emojis kept). Only the greeting differs: its blanks for
 * the agent name were replaced per the owner's ruling, then further refined
 * (overnight refinement change 1) to greet by the agent's name (ميار) and
 * the time of day via the {time_greeting} placeholder (App\Bot\Flow\ScriptPlaceholders).
 */
final class LeVoileScripts
{
    /** Owner-confirmed payment methods (2026-09-18): COD, Visa, Mastercard, Apple Pay, any e-wallet. */
    public const PAYMENT_TEXT = 'الدفع كاش عند الاستلام، أو فيزا / ماستركارد، أو Apple Pay، أو أي محفظة إلكترونية (من الموقع) 🌸';

    /** The placeholder payment_info was seeded with; replaced only while still untouched. */
    public const PAYMENT_PLACEHOLDER = '❓ محتاج طرق الدفع المتاحة';

    /** Overnight refinement change 3/4: the owner's default store link. */
    public const STORE_URL = 'https://levoilestores.com/';

    /** Swapped in for STORE_URL when the burst mentions scarves/caps. */
    public const SCARVES_URL = 'https://levoilescarfs.com/';

    /** Scripts whose store link is swapped for SCARVES_URL when the burst mentions scarves/caps. */
    public const SCARF_SWAP_SCRIPT_KEYS = ['availability', 'not_available', 'material_link', 'order_on_website'];

    /**
     * Normalized (ArabicNormalizer) scarf/cap words (owner's list). Kept as one
     * constant next to the code that uses it (TurnRunner::bodies()) instead of
     * scattering the list across the flow.
     */
    public const SCARF_WORDS = [
        'طرحه', 'طرح', 'كاب', 'كابات', 'بونيه', 'بونيهات', 'تلبيسه', 'تلبيسات', 'سكارف', 'بندانه', 'scarf', 'cap',
    ];

    /** Single letters that attach directly to the next word (بالطرح، للكاب، والكابات...). */
    private const ATTACHED_LETTERS = ['ب', 'ل', 'و', 'ك', 'ف'];

    /**
     * Whether the (untrusted, customer) text mentions a scarf/cap, by the
     * owner's word list. Fix round 1, issue 5: whole-word matching only --
     * "كاب" must not match inside "كابوس", "طرح" must not match inside
     * "مطروح" -- so the text is tokenized (letters/digits runs) and each
     * token is compared to a normalized scarf word exactly, the same way the
     * Latin word-boundary check already worked.
     *
     * Fix round 2: bare-"ال"-only stripping regressed a scarf word carrying an
     * attached preposition ("بالطرحة", "للكاب") back to a false negative. Each
     * token is now tried both as-is and against every way of stripping at most one
     * leading letter from ATTACHED_LETTERS, then optionally "ال" -- plus the
     * doubled-lam "لل" contraction (لـ + الـ with the alif elided) as its own case.
     */
    public static function mentionsScarves(string $text): bool
    {
        $normalizer = new ArabicNormalizer;
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $normalizer->normalize($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $scarfWords = array_map(fn (string $w) => $normalizer->normalize($w), self::SCARF_WORDS);

        foreach ($tokens as $token) {
            foreach (self::strippedForms($token) as $form) {
                if (in_array($form, $scarfWords, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> the token itself, plus every attached-letter/"ال"/"لل" stripping of it */
    private static function strippedForms(string $token): array
    {
        $forms = [$token];

        foreach (self::ATTACHED_LETTERS as $letter) {
            if (! str_starts_with($token, $letter) || mb_strlen($token) <= mb_strlen($letter)) {
                continue;
            }

            $rest = mb_substr($token, mb_strlen($letter));
            $forms[] = $rest;

            if (str_starts_with($rest, 'ال') && mb_strlen($rest) > 2) {
                $forms[] = mb_substr($rest, 2);
            }
        }

        if (str_starts_with($token, 'ال') && mb_strlen($token) > 2) {
            $forms[] = mb_substr($token, 2);
        }

        if (str_starts_with($token, 'لل') && mb_strlen($token) > 2) {
            $forms[] = mb_substr($token, 2);
        }

        return $forms;
    }

    /** @return array<string, array{title:string, body:string, active:bool}> key without the "script." prefix */
    public static function scripts(): array
    {
        return [
            'greeting' => ['title' => 'ترحيب', 'body' => '{time_greeting} يا فندم يومك حلو ان شاء الله 😍 مع حضرتك ميار من Le Voile', 'active' => true],
            'price' => ['title' => 'السعر', 'body' => 'Item name
details..💬
* Material :
* Dimension :
لتأكيد الأوردر عن طريق Website 👇
Thanks for choosing Le Voile 🌸', 'active' => true],
            'size' => ['title' => 'المقاسات', 'body' => 'المقاسات موضحه علي الويب سايت يا فندم بالطول و العرض فالافضل تراجعي المقاسات لانها ادق من الوزن اللي بيختلف من جسم للتاني', 'active' => true],
            'availability' => ['title' => 'الموديلات المتاحة', 'body' => 'ده لينك فيه جميع الموديلات المتاحه يا فندم
ممكن حضرتك تحددلنا الشكل إللي حضرتك محتاجه وابعتلك التفاصيل ❤️
https://levoilestores.com/', 'active' => true],
            'availability_branch' => ['title' => 'التوفر في الفروع', 'body' => 'كل الموديلات متاحه في كل الفروع يافندم ✨
- ممكن حضرتك تتواصلي معاهم للتاكيد من وجود المنتج او تشرفينا بنفسك وتشوفي كل الموديلات المتاحه
لكن مقدرش اضمن لحضرتك اللون او الكميه برجاء التواصل مع الفرع للتأكد 💕
- بالاضافه ان وارد ان المنتج يكون غير متاح ف الفروع لانه راجع حسب الضغط علي كل فرع 🌸', 'active' => true],
            'material' => ['title' => 'الخامة والعناية', 'body' => 'المنتج مصنوع من        و طريقة الغسيل و العناية موضحه علي الويب سايت بالفعل
طريقة الغسيل بتكون يدوي بماء بارد و بمسحوق خفيف زي الجل و بدون فرك او عصر', 'active' => true],
            'tracking_order' => ['title' => 'متابعة أوردر', 'body' => 'ممكن رقم الاوردر او رقم الموبايل او الميل اللي تم بيه الاوردر عشان اقدر اساعد حضرتك 🌸', 'active' => true],
            'placing_order' => ['title' => 'تأكيد أوردر', 'body' => 'لتاكيد الاوردر برجاء ارسال :
الاسم بالكامل
- العنوان تفصيلي "المحافظة - المنطقة"
- رقم الموبايل
- غير متاح فتح الاوردر في تواجد المندوب
- وفي حاله حدوث اي مشكله بيتم التواصل معنا وبيتم عمل استبدال او استرجاع من خلالنا او من الفرع مع وجود الفاتوره .
مع العلم ان غير متاح استبدال او استرجاع في :
"المنتجات القطنيه والإكسسوارات ومكملات الحجاب والاسدالات portable والبوركيني والكاش مايوه"
بالاضافة ان في حالة وجود خصم علي القطعه بيكون متاح استبدال فقط غير متاح استرجاعها', 'active' => true],
            'edit_order' => ['title' => 'تعديل أوردر', 'body' => 'ممكن رقم الاوردر او رقم الموبايل او الميل اللي تم بيه الاوردر عشان اقدر اساعد حضرتك 🌸
حابه اوضح لحضرتك ان الغاء الاوردر او تعديله بيكون في خلال ساعتين فقط من وقت طلب الأوردر', 'active' => true],
            'delivery_time' => ['title' => 'مدة التوصيل', 'body' => 'الاوردر بيوصل القاهرة / الجيزة / الاسكندريه خلال 3-5 ايام عمل .
باقي المحافظات خلال 5-7 ايام عمل ✨
غير محسوب الاجازات الرسميه والاسبوعيه', 'active' => true],
            // Never a number here: TurnRunner adds the live Shopify rate as a fact (ShippingFeeAnswer).
            'shipping_fee' => ['title' => 'مصاريف الشحن', 'body' => 'مصاريف الشحن بتتحسب حسب المحافظة وبتظهر لحضرتك قبل تأكيد الأوردر على الويب سايت 🌸', 'active' => true],
            'track_shipped' => ['title' => 'متابعة أوردر مشحون', 'body' => 'تم التواصل مع شركه الشحن لمتابعه الاوردر و هيتم التواصل مع حضرتك من خلالهم في اقرب وقت للتسليم', 'active' => true],
            'cancel_order' => ['title' => 'إلغاء أوردر', 'body' => 'ممكن رقم الاوردر او رقم الموبايل او الميل اللي تم بيه الاوردر عشان اقدر اساعد حضرتك 🌸
حابه اوضح لحضرتك ان فيما بعد الغاء الاوردر بيكون في خلال ساعتين فقط من الطلب لان بيكون في تحمل لمصاريف الشحن', 'active' => true],
            'return_policy' => ['title' => 'سياسة الاستبدال والاسترجاع', 'body' => 'سياسة الاستبدال او الاسترجاع:-
-غير متاح معاينة الأوردر قبل الاستلام أو تجزئته
- الاستبدال او الاسترجاع بيكون خلال 14 يوم من تاريخ استلام الاوردر
في حالة وجود مشكله في الاوردر بيتم التواصل معنا وعمل اوردر استرجاع لو الديفوه من عندنا بيتم الاستبدال بدون مصاريف شحن
اما لو المشكله في المقاس او الموديل مش مناسب حضرتك بتتحملي مصاريف الشحن او ترجعيها لأقرب فرع ليكي بالريسيت
شرط ان تكون القطعه في حالتها الاصليه الي وصلت بيها
مع العلم غير متاح استبدال او استرجاع بعض الموديلات مثل
"المنتجات القطنيه (البونيهات , التربون , الباديهات) والإكسسوارات ومكملات الحجاب والاسدالات ال portable والبوركيني والكاش مايوه" 🌸
بالاضافه ان لو القطعه عليها خصم بيكون متاح استبدالها فقط', 'active' => true],
            'product_defect' => ['title' => 'منتج به عيب', 'body' => 'ممكن رقم الاوردر او رقم الموبايل او الميل اللي تم بيه الاوردر عشان اقدر اساعد حضرتك 🌸
او صورة الفاتورة
• صورة واضحة للمنتج
• صورة توضح الديفوه الموجود في المنتج
• صورة الكود الموجود على التيكت 🌸', 'active' => true],
            'exchange_no_defect' => ['title' => 'استبدال/استرجاع بدون عيب', 'body' => 'ممكن رقم الاوردر او رقم الموبايل او الميل اللي تم بيه الاوردر عشان اقدر اساعد حضرتك
او صورة الفاتورة
• صورة واضحة للمنتج
• صورة الكود الموجود على التيكت', 'active' => true],
            'exchange_branch' => ['title' => 'استبدال/استرجاع من الفرع', 'body' => 'تقدري تستبدلي او تسترجعي من اقرب فرع لحضرتك بالفتورة خلال 14 يوم فقط
مع العلم ان غير متاح استبدال او استرجاع في :
"المنتجات القطنيه والإكسسوارات ومكملات الحجاب والاسدالات portable والبوركيني والكاش مايوه"
بالاضافة ان في حالة وجود خصم علي القطعه بيكون متاح استبدال فقط غير متاح استرجاعها', 'active' => true],
            'exchange_branch_defect' => ['title' => 'استبدال/استرجاع من الفرع - عيب', 'body' => 'ممكن حضرتك ترجعي للفرع نقسه توضحي لهم المشكله اللي مع حضرتك لان احنا اونلاين فقط ادارة منفصله عن الفروع ولو في اي خطا من ناحيتنا بعد المراجعه تأكدي ان هيتم حله', 'active' => true],
            'refund_request' => ['title' => 'طلب ريفوند', 'body' => 'تم عمل الريفوند لحضرتك بمجرد تحويل المبلغ نبعت لحضرتك سكرين شوت بالتحويل و بيسمع في حسابك في خلال من 7 ل 14 يوم عمل', 'active' => true],
            'refund_followup' => ['title' => 'متابعة ريفوند', 'body' => 'تم عمل ريفوند لحضرتك بعد رجوع القطعه لينا من شركه الشحن بيتم تحويل المبلغ لحضرتك من قبل قسم الحسابات و بنبعت لك سكرين شوت بالتحويل و بتسمع في حسابك خلال من 7 ل 14 يوم عمل', 'active' => true],
            'refund_closing' => ['title' => 'رسالة ختام الريفوند', 'body' => 'أهلاً 🤍
شكرًا لتواصلك معنا بخصوص الريفوند. أحب أطمنك أن جميع الريفوندات تتم وفقًا للإجراءات الرسمية للشركة، وعادةً ما تستغرق من 7 إلى 14 يوم عمل منذ إتمام العملية من قبلنا.
نحن ملتزمون بهذه الفترة لضمان أن جميع العمليات تتم بشكل آمن ومنظم، ولا يمكن تجاوز هذا الإطار الزمني.
في حال تجاوز المدة، يمكنك التواصل معنا مرة أخرى لمتابعة حالتك، وسنقوم بالتحقق مع فريق الحسابات لضمان استلامك للمستحقات في أسرع وقت ممكن.
شاكرين لك صبرك وتفهمك 🌸', 'active' => true],
            'branch_complaint' => ['title' => 'شكوى فرع', 'body' => 'يرجى إرسال: الاسم، رقم للتواصل، مع حضرتك فاتورة ؟ ، تاريخ زيارة الفرع', 'active' => true],
            'delayed_refund_branch' => ['title' => 'تأخير ريفوند من الفرع', 'body' => 'يرجى إرسال: صورة الفاتورة، صورة إيصال الدفع بالفيزا، صورة إيصال الريفوند', 'active' => true],
            'payment_issue' => ['title' => 'مشكلة دفع', 'body' => 'يرجي توضيح طريقة الدفع , رقم الاوردر , و وصف المشكله بالتفصيل', 'active' => true],
            'price_too_high' => ['title' => 'السعر عالي', 'body' => 'السعر مناسب لجودة الخامة يا فندم و التفاصيل اللى بنهتم بيها عشان نضمن ان المنتج يكون بكواليتي عالية و مميز', 'active' => true],
            // Overnight refinement change 4: an out-of-stock answer now points the customer
            // at the available models instead of leaving her with nothing to do.
            'not_available' => ['title' => 'منتج غير متاح', 'body' => "للاسف حاليا المنتج غير متاح تابعينا دائما و بمجرد ما يتوفر بيكون متاح علي الويب سايت\nتقدري تشوفي الموديلات المتاحة من هنا 👇\nhttps://levoilestores.com/", 'active' => true],
            'delayed_response' => ['title' => 'تأخير الرد', 'body' => 'انا حابه اوضح لحضرتك ان في ضغط في الرسايل وبيتم الرد من الاقدم للاحدث فبالتالي الافضل يافندم ان يتم ارسال رساله او رسالتين في وقت واحد علي الاقل للرد علي حضرتك بشكل اسرع .. وفي حاله ارسال اكثر من رساله الشات بيطلع فوق والرد بيكون متاخر اكتر يافندم .. ويارب دايما عند حسن ظن حضرتك 🌸', 'active' => true],
            'international_shipping' => ['title' => 'الشحن خارج مصر', 'body' => 'الشحن خارج مصر :
- الاوردر بيتم عن طريق الويب سايت
- تكلفه الشحن بيتم تحدديها حسب وزن الشحنة
- مدة التوصيل من 7 الي 10 ايام
وده لينك الويب سايت 👇🏻 https://levoilestores.com/
Thanks for choosing Le Voile 🌸
—
International Shipping (Outside Egypt): Orders can be placed through our website. Shipping cost is calculated based on the weight of the package. Delivery time is between 7 to 10 days. Here\'s the website link 👇🏻 https://levoilestores.com/ Thanks for choosing Le Voile 🌸', 'active' => true],
            'promo_code' => ['title' => 'كود خصم', 'body' => '- للاسف يا فندم غير متاح تفعيل كود خصم من خلالنا
- للاسف حاليا غير متاح اكواد خصم', 'active' => true],
            'wholesale' => ['title' => 'الجملة', 'body' => 'نظام البيع "جمله"
البيع مش اقل من 6 دست مشكل
الديجتال مش اقل من 3 دست
و البونيه مش اقل من 10 دست
و الشيلان و الملابس مش اقل من 6. قطع
وتوتال الفاتوره 5000
- لابد يكون في منفذ بيع
ممكن حضرتك تتوصلي مع رقم مسئول الجمله : 01050092780 🌸', 'active' => true],
            'colors' => ['title' => 'الألوان', 'body' => 'جميع الالوان الموجودة علي الويب سايت حاليا .. في حاله عدم تواجد اللون بيكون خلص والالوان المتاحه هي المتوفرة فقط 🌸', 'active' => true],
            'inspection' => ['title' => 'المعاينة وقت الاستلام', 'body' => 'للأسف يا فندم، المعاينة مش متاحة وقت الاستلام، بس حضرتك بتستلمي المنتج زي ما هو متصور بالظبط على الموقع. ولو بعد الاستلام فيه أي مشكلة في المنتج، حضرتك بتتواصلي مع خدمة العملاء، وإحنا بنساعد حضرتك في الاستبدال أو حل أي مشكلة إن شاء الله 💖', 'active' => true],
            'owner_question' => ['title' => 'سؤال عن صاحبة البراند', 'body' => 'للاسف معندناش علم تحديدا احنا هنا خدمه العملاء
دا الاكونت البرايفت ل مدام ساره تقدري تستفسري منها 🌸
https://instagram.com/sarahesham_1_1_1?igshid=YmMyMTA2M2Y=', 'active' => true],
            'inner_caps_price' => ['title' => 'سعر البونيهات', 'body' => 'البونيهات
من 60 ج حتي 150 ج
لتأكيد الأوردر عن طريق ال Website 👇🏼
https://levoilestores.com/collections/inner-caps
Thanks for choosing Le Voile 🌸', 'active' => true],
            'feedback' => ['title' => 'رأي العميل', 'body' => 'شكرا لاهتمامك و إبداء رأيك ♥️ أحنا بنسمع لكل عميل و هنوصل ملاحظتك', 'active' => true],
            'return_policy_en' => ['title' => 'Return & Exchange Policy (EN)', 'body' => 'Orders cannot be opened in the presence of the delivery agent. Returns or exchanges are allowed within 14 days from the order delivery date. In case of an issue with the order, please contact us to create a return order. If the defect is from our side, the item will be replaced without any shipping charges. If the issue is due to the size or model not being suitable, you will be responsible for the shipping costs, or you can return it to the nearest branch with the receipt. The item must be in its original condition as received. Please note that some items cannot be returned or exchanged, including: Cotton products (bonnets, turbans, bodysuits), accessories, hijab supplements, abayas, burkinis, and swimwear. Additionally, if the item was purchased at a discounted price, it is eligible for exchange only.', 'active' => true],
            'discounts_branches' => ['title' => 'الخصومات في الفروع', 'body' => 'الخصومات متاحه اونلاين و في كل فروعنا يا فندم و لكن احنا اونلاين فقط ادارة منفصله عن الفروع لو حضرتك بتسألي علي قطعه محددة ممكن تتواصلي مع الفرع بنفسك تتأكدي من توافرها قبل التوجه للفرع و تم توضيح لحضرتك كل عناوين الفروع و ارقامهم', 'active' => true],
            'b2b_service_offer' => ['title' => 'عروض خدمات (B2B)', 'body' => 'Thank you for reaching out and for your interest. At the moment, we don\'t require this service, but we\'ll keep your details in case we need it in the future. Appreciate it.
شكرًا لرسالتكم واهتمامكم. في الوقت الحالي إحنا مش محتاجين الخدمة، ولو احتجنا مستقبلاً هنرجع نتواصل معاكم. تقديرنا ليكم.', 'active' => true],
            'events' => ['title' => 'دعوات الفعاليات', 'body' => 'Thank you for contacting us. We\'re not planning to participate in any events at the moment, but we look forward to potential collaboration in the near future 🙏🏻
شكرًا لتواصلكم معنا. في الوقت الحالي لا نشارك في أي فعاليات، لكن نتطلع بكل سرور إلى فرص تعاون مستقبلية قريبة 🙏🏻', 'active' => true],
            'careers' => ['title' => 'الوظائف والموديلز', 'body' => 'Hello beautiful 💕 Thank you for your interest in joining our team. Kindly send your pics (without filter) and IG account to this email careers@levoilestores.com and if your profile is accepted, one of our marketing team will contact you. Good luck dear 🤍', 'active' => true],
            // Controller ruling (not in the owner reference): one acknowledgement before a collect intent hands over.
            'handover_ack' => ['title' => 'تأكيد التحويل للفريق', 'body' => 'تمام يا فندم، هراجع طلب حضرتك مع الفريق حالًا وهرد عليكي 🌸', 'active' => true],
            // Overnight refinement change 2: a plain "thank you" answer, no handover.
            'thanks' => ['title' => 'شكر', 'body' => 'العفو يا فندم تحت أمرك في أي وقت 🌸', 'active' => true],
            // Reply flow v2 (2026-09-16): product questions answer with the website link, ordering
            // through us hands over with its own message, and the first reply offers a person.
            'material_link' => ['title' => 'الخامة والعناية (لينك)', 'body' => "طريقة الغسيل والعناية وتفاصيل الخامة موضحة على الويب سايت في صفحة كل منتج يا فندم 🌸\nhttps://levoilestores.com/", 'active' => true],
            'order_on_website' => ['title' => 'الطلب من الموقع', 'body' => "تقدري تطلبي مباشرة من الموقع يا فندم 👇\nhttps://levoilestores.com/\nولو حابة نسجل الأوردر مع حضرتك قوليلي وهحولك لموظف 🌸", 'active' => true],
            'order_via_agent' => ['title' => 'تسجيل الأوردر مع موظف', 'body' => 'تمام يا فندم، استني ثواني هحولك لموظف يسجل الأوردر مع حضرتك 🌸', 'active' => true],
            'offer_human' => ['title' => 'عرض التحويل لموظف', 'body' => 'لو حابة أحولك لموظف في أي وقت قوليلي 🌸', 'active' => true],
            // Placeholders the owner has not supplied yet (inactive until filled in).
            'branches_hours' => ['title' => 'الفروع ومواعيد العمل', 'body' => '❓ محتاج عناوين الفروع ومواعيد العمل', 'active' => false],
            // Owner-confirmed 2026-09-18 (was a ❓ placeholder).
            'payment_info' => ['title' => 'طرق الدفع', 'body' => self::PAYMENT_TEXT, 'active' => true],
        ];
    }

    /**
     * Rows for bot_intents, in spec §2.3 order. Keywords are the owner routing
     * table's "Keywords (AR)" plus common Franco/English variants; they are
     * hints for the understanding prompt and the offline keyword matcher.
     * Very generic single words from the owner table (e.g. "شحن", "فرع",
     * "طلب") are narrowed to phrases so the offline matcher does not route
     * every shipping or branch question to a complaint.
     *
     * @return list<array<string, mixed>>
     */
    public static function intents(): array
    {
        $row = fn (string $key, string $group, string $ar, string $en, string $route, string $priority, ?string $queue, array $scripts, array $details, array $keywords) => [
            'key' => $key, 'group' => $group, 'label_ar' => $ar, 'label_en' => $en, 'route' => $route, 'priority' => $priority,
            'queue' => $queue, 'script_keys' => $scripts, 'required_details' => $details, 'keywords' => $keywords,
        ];

        return [
            $row('greeting', 'general', 'ترحيب', 'Greeting', 'answer', 'low', null, ['greeting'], [], ['السلام عليكم', 'هاي', 'hi', 'hello', 'مساء الخير', 'صباح الخير', 'اهلا']),
            $row('price', 'general', 'سعر منتج', 'Price inquiry', 'answer', 'low', null, ['availability'], ['product'], ['سعر', 'السعر', 'بكام', 'price', 'bkam', 'b kam', 'se3r']),
            $row('size_info', 'general', 'المقاسات', 'Size info', 'answer', 'low', null, ['size'], [], ['مقاس', 'المقاسات', 'size', 'ma2as', 'ma2asat']),
            $row('availability', 'general', 'الموديلات المتاحة', 'Availability', 'answer', 'low', null, ['availability'], [], ['متاح', 'موجود', 'متوفر', 'available', 'metah', 'mawgood']),
            $row('availability_branch', 'general', 'التوفر في الفروع', 'Availability in branches', 'answer', 'low', null, ['availability_branch'], [], ['متاح في الفرع', 'موجود في الفرع', 'متوفر في الفروع', 'in store', 'fel fara3']),
            $row('material', 'general', 'الخامة', 'Material', 'answer', 'low', null, ['material_link'], [], ['الخامه', 'خامه', 'قماش', 'material', '5ama', 'khama']),
            $row('colors', 'general', 'الألوان', 'Colors', 'answer', 'low', null, ['colors'], [], ['الوان', 'لون', 'colors', 'color', 'alwan', 'lon']),
            $row('delivery_time', 'general', 'مدة التوصيل', 'Delivery time', 'answer', 'low', null, ['delivery_time'], [], ['التوصيل', 'مدة التوصيل', 'كام يوم', 'بيوصل امتي', 'delivery', 'tawseel', 'twseel']),
            // "الشحن بكام": answered with the live Shopify rate (TurnRunner::SHIPPING_COST_INTENT), never a scripted fee.
            $row('shipping_cost', 'general', 'مصاريف الشحن', 'Shipping cost', 'answer', 'low', null, ['shipping_fee'], [], ['الشحن بكام', 'الشحن كام', 'الشحن ب كام', 'سعر الشحن', 'مصاريف الشحن', 'مصاريف شحن', 'تكلفة الشحن', 'التوصيل بكام', 'shipping cost', 'shipping fee', 'sha7n bkam']),
            $row('international_shipping', 'general', 'الشحن خارج مصر', 'International shipping', 'answer', 'low', null, ['international_shipping'], [], ['خارج مصر', 'برا مصر', 'بره مصر', 'international', 'outside egypt']),
            $row('return_policy', 'general', 'سياسة الاستبدال والاسترجاع', 'Return & exchange policy', 'answer', 'low', null, ['return_policy'], [], ['السياسه', 'سياسة الاستبدال', 'سياسة الاسترجاع', 'policy', 'return policy']),
            // Overnight refinement change 2: merged in booking hints (احجز, عايزة اطلب, عاوزه اطلب, اطلب) alongside the existing ones.
            $row('how_to_order', 'general', 'ازاي اطلب', 'How to order', 'answer', 'low', null, ['order_on_website'], [], ['ازاي اطلب', 'اطلب ازاي', 'اعمل طلب', 'عايزه اطلب', 'how to order', 'ezay atlob', 'azay atlob', 'احجز', 'عايزة اطلب', 'عاوزه اطلب', 'اطلب']),
            $row('branches_hours', 'general', 'الفروع ومواعيد العمل', 'Branches & working hours', 'answer', 'low', null, ['branches_hours'], [], ['اللوكيشن', 'الفروع', 'ساعات العمل', 'مواعيد العمل', 'location', 'branches', 'hours']),
            $row('payment_info', 'general', 'طرق الدفع', 'Payment info', 'answer', 'low', null, ['payment_info'], [], ['طرق الدفع', 'الدفع', 'فيزا', 'انستاباي', 'payment']),
            $row('promo_code', 'general', 'كود خصم', 'Promo code', 'answer', 'low', null, ['promo_code'], [], ['كود خصم', 'كود الخصم', 'برومو', 'كوبون', 'promo', 'coupon', 'discount code']),
            $row('wholesale', 'general', 'الجملة', 'Wholesale', 'answer', 'low', null, ['wholesale'], [], ['جمله', 'بالجمله', 'wholesale', 'gomla']),
            $row('inspection_on_delivery', 'general', 'المعاينة وقت الاستلام', 'Inspection on delivery', 'answer', 'low', null, ['inspection'], [], ['معاينه', 'افتح الاوردر', 'اشوف الاوردر قبل', 'inspection']),
            $row('price_too_high', 'general', 'السعر عالي', 'Price too high', 'answer', 'low', null, ['price_too_high'], [], ['غالي', 'غاليه', 'الاسعار عاليه', 'expensive', 'ghaly', '8aly']),
            $row('feedback', 'general', 'رأي العميل', 'Feedback', 'answer', 'low', null, ['feedback'], [], ['رايي', 'ملاحظه', 'اقتراح', 'feedback']),
            $row('b2b_service_offer', 'general', 'عرض خدمات (B2B)', 'Service offer (B2B)', 'answer', 'low', null, ['b2b_service_offer'], [], ['نقدم خدمه', 'عرض خدمه', 'خدماتنا', 'our services', 'we offer']),
            $row('events', 'general', 'دعوة فعالية', 'Event invitation', 'answer', 'low', null, ['events'], [], ['ايفنت', 'فعاليه', 'بازار', 'event']),
            $row('careers', 'general', 'وظائف وموديلز', 'Careers / modeling', 'answer', 'low', null, ['careers'], [], ['وظيفه', 'وظايف', 'اشتغل معاكم', 'موديل تصوير', 'careers', 'job', 'hiring']),
            // Overnight refinement change 2 (inbox analysis 2026-09-14): four gaps found in the real inbox.
            $row('human_request', 'general', 'طلب التواصل مع موظف', 'Human request', 'handover', 'medium', 'agents', [], [], ['رقم واتس', 'واتساب', 'واتس', 'whatsapp', 'حد اكلمه', 'حد اتواصل معاه', 'اكلم حد', 'عايزة اكلم حد', 'موظف', 'خدمة العملاء', 'customer service']),
            $row('order_via_agent', 'general', 'طلب أوردر من خلالنا', 'Order through an agent', 'handover', 'medium', 'agents', ['order_via_agent'], [], ['اعملولي الاوردر', 'اعملي الاوردر', 'تعملولي الاوردر', 'سجلي الاوردر', 'سجلولي الاوردر', 'عايزة اطلب منكم', 'اطلب منكم', 'مش عارفة اطلب من الموقع', 'مش عارفه اطلب', 'تسجلوا الاوردر']),
            // Fix round 1, issue 1: bare "عرض"/"العرض" removed (collided with the size script's
            // "بالطول و العرض"); added the "في عرض"/"فيه عرض"/"عليه عرض"/"نازل عليه" phrases.
            $row('sale_offer', 'general', 'سؤال عن العروض', 'Sale / offer question', 'handover', 'medium', 'agents', [], [], ['sale', 'سيل', 'عروض', 'اوفر', 'offer', 'تخفيضات', 'في عرض', 'فيه عرض', 'عليه عرض', 'نازل عليه']),
            // Fix round 1, issue 4: "تحفه" removed (collided with product compliments like "الفستان ده تحفه").
            $row('thanks', 'general', 'شكر', 'Thanks', 'answer', 'low', null, ['thanks'], [], ['شكرا', 'متشكره', 'مرسي', 'تسلمي', 'thank', 'thanks']),
            $row('fabric_season', 'general', 'سؤال عن الخامة أو الموسم', 'Fabric / season question', 'handover', 'low', 'agents', [], [], ['شتوي', 'صيفي', 'نص كم', 'كم طويل', 'sleeves']),
            $row('order_status', 'tracking', 'حالة الأوردر', 'Order status', 'lookup', 'medium', 'agents', ['tracking_order', 'track_shipped'], ['order_ref|phone|email'], ['حالة الاوردر', 'تتبع اوردر', 'الاوردر فين', 'اوردري', 'order status', 'track', 'tracking', 'فين الاوردر', 'طلبي', 'تتبع']),
            $row('delayed_order', 'tracking', 'أوردر متأخر', 'Delayed order', 'lookup', 'high', 'agents', ['tracking_order'], ['order_ref|phone|email'], ['متاخر', 'اتاخر', 'delayed', 'late']),
            $row('no_update', 'tracking', 'مفيش جديد في الأوردر', 'No update on order', 'lookup', 'high', 'agents', ['tracking_order'], ['order_ref|phone|email'], ['مفيش جديد', 'مفيش تحديث', 'no update']),
            $row('cancel_order', 'order_action', 'إلغاء أوردر', 'Cancel order', 'collect_then_handover', 'medium', 'agents', ['cancel_order'], ['order_ref|phone|email'], ['الغاء', 'الغي', 'cancel', 'alghy']),
            $row('edit_order', 'order_action', 'تعديل أوردر', 'Edit order', 'collect_then_handover', 'medium', 'agents', ['edit_order'], ['order_ref|phone|email'], ['تعديل', 'اعدل', 'اغير العنوان', 'edit', 'change address']),
            $row('exchange_return', 'order_action', 'استبدال أو استرجاع', 'Return & exchange request', 'collect_then_handover', 'medium', 'agents', ['exchange_no_defect'], ['order_ref|phone|email', 'photos'], ['استبدال', 'استرجاع', 'ابدل', 'ارجع', 'exchange', 'return']),
            $row('defect', 'complaints', 'منتج به عيب', 'Defect', 'collect_then_handover', 'high', 'agents', ['product_defect'], ['order_ref|phone|email', 'photos'], ['عيب', 'مقطوع', 'شايط', 'ديفوه', 'defect', 'damaged']),
            $row('wrong_item', 'complaints', 'أوردر غلط', 'Wrong order', 'collect_then_handover', 'high', 'agents', ['product_defect'], ['order_ref|phone|email', 'photos'], ['غلط', 'خطا', 'مش اللي طلبته', 'wrong']),
            $row('missing_item', 'complaints', 'قطعة ناقصة', 'Missing item', 'collect_then_handover', 'high', 'agents', ['product_defect'], ['order_ref|phone|email', 'photos'], ['ناقص', 'ناقصه', 'missing']),
            // Final fix wave C1: script.refund_request says the refund is already done, so the ask is
            // the owner's "send the order number" script; a person confirms the refund after the handover.
            $row('refund', 'refund', 'ريفوند', 'Refund', 'collect_then_handover', 'medium', 'agents', ['tracking_order'], ['order_ref'], ['ريفوند', 'استرداد', 'فلوسي', 'refund']),
            $row('delivery_problem', 'complaints', 'مشكلة توصيل', 'Delivery problem', 'handover', 'high', 'agents', [], [], ['مندوب', 'شركه الشحن', 'مشكله في التوصيل', 'مشكله في الشحن', 'courier']),
            $row('store_complaint', 'store_complaints', 'تجربة سيئة في الفرع', 'Store bad experience', 'collect_then_handover', 'high', 'senior', ['branch_complaint'], ['name', 'phone', 'invoice?', 'visit_date'], ['وحش', 'سيئ', 'سوء تعامل', 'bad experience']),
            $row('branch_issue', 'store_complaints', 'مشكلة في الفرع', 'Branch issue', 'collect_then_handover', 'high', 'senior', ['branch_complaint'], ['name', 'phone', 'invoice?', 'visit_date'], ['مشكله في الفرع', 'شكوى الفرع', 'موظفين الفرع', 'branch complaint']),
            $row('payment_issue', 'payment', 'مشكلة دفع', 'Payment issue', 'collect_then_handover', 'high', 'agents', ['payment_issue'], ['method', 'order_ref', 'description'], ['مشكله في الدفع', 'الفلوس اتسحبت', 'اتخصم مني', 'payment issue', 'payment problem']),
            $row('angry', 'edge_cases', 'عميلة غاضبة', 'Angry customer', 'handover', 'high', 'agents', [], [], ['زفت', 'نصب', 'اسوا تعامل', 'حرام عليكم']),
            // Seeded inactive: urgency alone ("متاح دلوقتي؟") must not hand over; it still feeds
            // the router's angry + urgent rule through Understanding::$urgent.
            ['is_active' => false] + $row('urgent', 'edge_cases', 'طلب عاجل جدا', 'Very urgent', 'handover', 'high', 'agents', [], [], ['بسرعه', 'ضروري', 'دلوقتي', 'urgent']),
        ];
    }
}
