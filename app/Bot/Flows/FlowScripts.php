<?php

namespace App\Bot\Flows;

/**
 * Closing and knowledge scripts for the guided flows (Task 3, design doc
 * §flows), the handover transfer sentences and the greeting mirrors. Seeded as
 * `bot_knowledge_entries` rows keyed `script.<key>`, so the owner edits every one
 * of them from Settings → معرفة البوت.
 * Exact Arabic texts and keys per the task brief — do not reword.
 */
final class FlowScripts
{
    /** @return array<string, array{title:string, body:string}> */
    public static function all(): array
    {
        return [
            'flow_return_policy_short' => ['title' => 'سياسة المرتجع (مختصرة)', 'body' => "حابة أوضح لحضرتك إن الاستبدال أو الاسترجاع بيكون خلال 14 يوم من استلام الأوردر، والقطعة تكون بحالتها الأصلية 🌸\nوفي منتجات مش متاحة للاستبدال أو الاسترجاع زي المنتجات القطنية والإكسسوارات ومكملات الحجاب والإسدالات الـ portable والبوركيني والكاش مايوه، والقطعة اللي عليها خصم متاح استبدالها بس."],
            'flow_photo_received' => ['title' => 'استلام صورة', 'body' => 'تمام وصلتني الصورة 🌸'],
            'flow_return_recorded' => ['title' => 'تسجيل مرتجع', 'body' => 'تم تسجيل طلب حضرتك برقم #{case_id} وهيتم التواصل مع حضرتك في أقرب وقت 🌸'],
            'flow_complaint_recorded' => ['title' => 'تسجيل شكوى', 'body' => 'تم تسجيل شكوى حضرتك برقم #{case_id} وهيتم التواصل مع حضرتك في أقرب وقت 🙏'],
            'flow_cancel_recorded' => ['title' => 'تسجيل إلغاء/تعديل', 'body' => "تم تسجيل طلب حضرتك برقم #{case_id} وهيتم التواصل مع حضرتك في أقرب وقت 🌸\nحابة أوضح إن الإلغاء أو التعديل متاح خلال ساعتين بس من وقت الطلب."],
            'flow_offer_human' => ['title' => 'عرض موظف', 'body' => 'تحب نحولك لموظف يساعد حضرتك؟'],
            'flow_case_exists' => ['title' => 'طلب متسجل', 'body' => 'طلب حضرتك متسجل برقم #{case_id} وهيتم التواصل مع حضرتك 🌸 تحب نحولك لموظف؟'],
            // 2026-09-21 §3: the first miss never repeats the question word for word — it
            // apologises and shows the same options again in ONE message.
            'flow_retry' => ['title' => 'إعادة السؤال (أول مرة)', 'body' => 'معلش مش واضحة ليا 🙏 اختاري من دول:'],
            // §3: the second miss stops asking and offers a person or the menu.
            'flow_not_understood' => ['title' => 'إعادة السؤال (تاني مرة)', 'body' => 'معلش، لسه مش قادرة أفهم قصدك 🙏 تحبي أوصلك لموظف يساعدك؟'],
            // §6.1: after answering a question that came in the middle of a flow.
            'flow_back_to' => ['title' => 'الرجوع للفلو بعد سؤال', 'body' => 'نرجع لـ{flow_label} 🌸'],
            // §6.1: she keeps asking other things — offer a person instead of going round.
            'flow_too_many_detours' => ['title' => 'أسئلة كتير جوه الفلو', 'body' => 'عشان نخلص طلب حضرتك صح، تحبي أوصلك لموظف يساعدك؟'],
            // §6.2: she asked for another flow while one is running.
            'flow_switch_offer' => ['title' => 'تغيير الفلو', 'body' => 'تحبي نسيب {from_label} ونتابع {to_label}؟'],
            // §6.3: thanks in the middle of a flow — one line, then the same step again.
            'flow_thanks' => ['title' => 'رد على الشكر جوه الفلو', 'body' => 'العفو يا قمر 🌸'],
            // §6.5: she came back after a long silence.
            'flow_resume_offer' => ['title' => 'استكمال بعد انقطاع', 'body' => 'لسه فاكرين طلبك 🌸 تحبي نكمل من حيث ما وقفنا؟'],
            // §3: she tapped a button of a step the flow has already passed.
            'flow_stale_tap' => ['title' => 'زرار من خطوة قديمة', 'body' => 'إحنا خلصنا الخطوة دي فعلًا 🌸'],
            // §6.6: nothing matched and no flow is running — never a dead end.
            'flow_menu_fallback' => ['title' => 'مفيش فلو ومفيش إجابة', 'body' => 'أقدر أساعدك في 👇'],
            'flow_not_found_order' => ['title' => 'أوردر مش موجود', 'body' => 'مش لاقية أوردر بالبيانات دي 🌸 ممكن تتأكدي من الرقم؟'],
            // The owner's flow 7 (2026-09-19): «كلم موظف» asks the topic first, then a working-hours aware reply.
            'handover_ask_topic' => ['title' => 'كلم موظف: سؤال الموضوع', 'body' => 'أكيد 🌸 ممكن تقوليلي باختصار محتاجة إيه؟ عشان أوصّلك للشخص المناسب على طول'],
            'handover_in_hours' => ['title' => 'التحويل في مواعيد العمل', 'body' => 'تمام ✅ هيتم تحويلك لموظف خدمة العملاء خلال دقايق 🌸'],
            'handover_after_hours' => ['title' => 'التحويل برّه مواعيد العمل', 'body' => 'تمام ✅ سجلت طلبك، وهيتم تحويلك لموظف خدمة العملاء أول ما نفتح {next_opening} 🌸'],
            'handover_no_hours' => ['title' => 'التحويل (من غير مواعيد عمل)', 'body' => 'تمام ✅ هيتم تحويلك لموظف خدمة العملاء، هيرد عليكي في أقرب وقت 🌸'],
            // Greeting mirrors (2026-09-21): the bot greets back the way she greeted, before
            // the {time_greeting} line and the menu (App\Bot\Flows\GreetingMirror).
            'greeting_mirror_salam' => ['title' => 'رد التحية: السلام عليكم', 'body' => 'وعليكم السلام ورحمة الله 🌸'],
            'greeting_mirror_sabah' => ['title' => 'رد التحية: صباح الخير', 'body' => 'صباح النور'],
            'greeting_mirror_sabah_full' => ['title' => 'رد التحية: صباح الفل', 'body' => 'صباح الفل والنور'],
            'greeting_mirror_sabah_noor' => ['title' => 'رد التحية: صباح النور', 'body' => 'صباح النور'],
            'greeting_mirror_masa' => ['title' => 'رد التحية: مساء الخير', 'body' => 'مساء النور'],
            'greeting_mirror_masa_full' => ['title' => 'رد التحية: مساء الفل', 'body' => 'مساء الفل والنور'],
            'greeting_mirror_masa_noor' => ['title' => 'رد التحية: مساء النور', 'body' => 'مساء النور'],
            'greeting_mirror_hi' => ['title' => 'رد التحية: أهلاً / هاي', 'body' => 'أهلاً بيكي 🌸'],
        ];
    }
}
