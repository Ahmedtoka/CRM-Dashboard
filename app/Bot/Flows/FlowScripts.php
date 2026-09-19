<?php

namespace App\Bot\Flows;

/**
 * Closing and knowledge scripts for the guided flows (Task 3, design doc
 * §flows). Seeded as `bot_knowledge_entries` rows keyed `script.<key>`.
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
            'flow_retry' => ['title' => 'إعادة السؤال', 'body' => 'معلش مفهمتش 🙏'],
            'flow_not_found_order' => ['title' => 'أوردر مش موجود', 'body' => 'مش لاقية أوردر بالبيانات دي 🌸 ممكن تتأكدي من الرقم؟'],
            // The owner's flow 7 (2026-09-19): «كلم موظف» asks the topic first, then a working-hours aware reply.
            'handover_ask_topic' => ['title' => 'كلم موظف: سؤال الموضوع', 'body' => 'أكيد 🌸 ممكن تقوليلي باختصار محتاجة إيه؟ عشان أوصّلك للشخص المناسب على طول'],
            'handover_in_hours' => ['title' => 'التحويل في مواعيد العمل', 'body' => 'تمام ✅ حولتك لحد من الفريق، هيرد عليكي خلال دقايق 🌸'],
            'handover_after_hours' => ['title' => 'التحويل برّه مواعيد العمل', 'body' => 'تمام ✅ سجلت طلبك، وفريق خدمة العملاء هيرد عليكي أول ما نفتح {next_opening} 🌸'],
            'handover_no_hours' => ['title' => 'التحويل (من غير مواعيد عمل)', 'body' => 'تمام ✅ حولتك لحد من الفريق، هيرد عليكي في أقرب وقت 🌸'],
        ];
    }
}
