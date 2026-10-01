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
        // The queue's counts arrive already worded (App\Queue\QueueWording): {ahead} is a whole
        // phrase («إنتي أول واحدة في الدور» / «قدامك عميلتين» …), {eta_minutes} / {minutes} carry
        // their unit («دقيقة» / «دقيقتين» / «5 دقايق» / «15 دقيقة»).
        return [
            'flow_return_policy_short' => ['title' => 'سياسة المرتجع (مختصرة)', 'body' => "حابة أوضح لحضرتك إن الاستبدال أو الاسترجاع بيكون خلال 14 يوم من استلام الأوردر، والقطعة تكون بحالتها الأصلية\nوفي منتجات مش متاحة للاستبدال أو الاسترجاع زي المنتجات القطنية والإكسسوارات ومكملات الحجاب والإسدالات الـ portable والبوركيني والكاش مايوه، والقطعة اللي عليها خصم متاح استبدالها بس."],
            'flow_photo_received' => ['title' => 'استلام صورة', 'body' => 'تمام وصلتني الصورة'],
            'flow_return_recorded' => ['title' => 'تسجيل مرتجع', 'body' => 'تم تسجيل طلب حضرتك برقم #{case_id} وهيتم التواصل مع حضرتك في أقرب وقت'],
            'flow_complaint_recorded' => ['title' => 'تسجيل شكوى', 'body' => 'تم تسجيل شكوى حضرتك برقم #{case_id} وهيتم التواصل مع حضرتك في أقرب وقت'],
            'flow_cancel_recorded' => ['title' => 'تسجيل إلغاء/تعديل', 'body' => "تم تسجيل طلب حضرتك برقم #{case_id} وهيتم التواصل مع حضرتك في أقرب وقت\nحابة أوضح إن الإلغاء أو التعديل متاح خلال ساعتين بس من وقت الطلب."],
            'flow_offer_human' => ['title' => 'عرض موظف', 'body' => 'تحب نحولك لموظف يساعد حضرتك؟'],
            'flow_case_exists' => ['title' => 'طلب متسجل', 'body' => 'طلب حضرتك متسجل برقم #{case_id} وهيتم التواصل مع حضرتك، تحب نحولك لموظف؟'],
            // 2026-09-21 §3: the first miss never repeats the question word for word — it
            // apologises and shows the same options again in ONE message.
            'flow_retry' => ['title' => 'إعادة السؤال (أول مرة)', 'body' => 'معلش مش واضحة ليا، اختاري من دول:'],
            // §3: the second miss stops asking and offers a person or the menu.
            'flow_not_understood' => ['title' => 'إعادة السؤال (تاني مرة)', 'body' => 'معلش، لسه مش قادرة أفهم قصدك، تحبي أوصلك لموظف يساعدك؟'],
            // §6.1: after answering a question that came in the middle of a flow.
            'flow_back_to' => ['title' => 'الرجوع للفلو بعد سؤال', 'body' => 'نرجع لـ «{flow_label}»'],
            // §6.1: she keeps asking other things — offer a person instead of going round.
            'flow_too_many_detours' => ['title' => 'أسئلة كتير جوه الفلو', 'body' => 'عشان نخلص طلب حضرتك صح، تحبي أوصلك لموظف يساعدك؟'],
            // §6.2: she asked for another flow while one is running.
            'flow_switch_offer' => ['title' => 'تغيير الفلو', 'body' => 'تحبي نسيب {from_label} ونتابع {to_label}؟'],
            // §6.3: thanks in the middle of a flow — one line, then the same step again.
            'flow_thanks' => ['title' => 'رد على الشكر جوه الفلو', 'body' => 'العفو يا قمر'],
            // §6.5: she came back after a long silence.
            'flow_resume_offer' => ['title' => 'استكمال بعد انقطاع', 'body' => 'لسه فاكرين طلبك، تحبي نكمل من حيث ما وقفنا؟'],
            // §3: she tapped a button of a step the flow has already passed.
            'flow_stale_tap' => ['title' => 'زرار من خطوة قديمة', 'body' => 'إحنا خلصنا الخطوة دي فعلًا'],
            // §6.6: nothing matched and no flow is running — never a dead end.
            'flow_menu_fallback' => ['title' => 'مفيش فلو ومفيش إجابة', 'body' => 'أقدر أساعدك في حاجة من دول:'],
            'flow_not_found_order' => ['title' => 'أوردر مش موجود', 'body' => 'مش لاقية أوردر بالبيانات دي، ممكن تتأكدي من الرقم؟'],
            // The owner's flow 7 (2026-09-19): «كلم موظف» asks the topic first, then a working-hours aware reply.
            'handover_ask_topic' => ['title' => 'كلم موظف: سؤال الموضوع', 'body' => 'أكيد، ممكن تقوليلي باختصار محتاجة إيه؟ عشان أوصّلك للشخص المناسب على طول'],
            'handover_in_hours' => ['title' => 'التحويل في مواعيد العمل', 'body' => 'تمام، هيتم تحويلك لموظف خدمة العملاء خلال دقايق'],
            'handover_after_hours' => ['title' => 'التحويل برّه مواعيد العمل', 'body' => 'تمام، سجلت طلبك، وهيتم تحويلك لموظف خدمة العملاء أول ما نفتح {next_opening}'],
            'handover_no_hours' => ['title' => 'التحويل (من غير مواعيد عمل)', 'body' => 'تمام، هيتم تحويلك لموظف خدمة العملاء، هيرد عليكي في أقرب وقت'],
            // She keeps writing while she waits for a person (owner, 2026-09-21): one
            // reassurance, then at most once every crm.bot.waiting_ack_minutes.
            'waiting_ack_in_hours' => ['title' => 'في انتظار الموظف (في المواعيد)', 'body' => 'رسايلك وصلت للفريق، وهيتم الرد على حضرتك في أقرب وقت'],
            'waiting_ack_after_hours' => ['title' => 'في انتظار الموظف (برّه المواعيد)', 'body' => 'رسايلك وصلت للفريق، وهيتم الرد على حضرتك أول ما نفتح {next_opening}'],
            'waiting_ack_no_hours' => ['title' => 'في انتظار الموظف (من غير مواعيد)', 'body' => 'رسايلك وصلت للفريق، وهيتم الرد على حضرتك في أقرب وقت'],
            // The store is slow to answer while we look up the exchange product (owner, 2026-09-21).
            'product_lookup_slow' => ['title' => 'لسه بدور على المنتج', 'body' => 'لسه بدور، ثواني كمان'],
            // The catalog as picture cards (owner, 2026-09-22; App\Bot\Catalog\ProductBrowser).
            'products_intro' => ['title' => 'الموديلات بالصور: الجملة اللي قبل الكروت', 'body' => 'دي أحدث الموديلات المتاحة عندنا، اضغطي «التفاصيل والمقاسات» على أي موديل يعجبك'],
            'products_type_intro' => ['title' => 'الموديلات بالصور: نوع معين', 'body' => 'موديلات {type} المتاحة'],
            // Greeting mirrors (2026-09-21): the bot greets back the way she greeted, before
            // the {time_greeting} line and the menu (App\Bot\Flows\GreetingMirror).
            'greeting_mirror_salam' => ['title' => 'رد التحية: السلام عليكم', 'body' => 'وعليكم السلام ورحمة الله'],
            'greeting_mirror_sabah' => ['title' => 'رد التحية: صباح الخير', 'body' => 'صباح النور'],
            'greeting_mirror_sabah_full' => ['title' => 'رد التحية: صباح الفل', 'body' => 'صباح الفل والنور'],
            'greeting_mirror_sabah_noor' => ['title' => 'رد التحية: صباح النور', 'body' => 'صباح النور'],
            'greeting_mirror_masa' => ['title' => 'رد التحية: مساء الخير', 'body' => 'مساء النور'],
            'greeting_mirror_masa_full' => ['title' => 'رد التحية: مساء الفل', 'body' => 'مساء الفل والنور'],
            'greeting_mirror_masa_noor' => ['title' => 'رد التحية: مساء النور', 'body' => 'مساء النور'],
            'greeting_mirror_hi' => ['title' => 'رد التحية: أهلاً / هاي', 'body' => 'أهلاً بيكي'],
            // The handover queue's customer-facing texts (2026-09-29 design spec).
            'queue_enqueued' => ['title' => 'الطابور: دخلت الدور', 'body' => 'تمام، هيتم تحويلك لموظفة خدمة العملاء. رقم تذكرتك #{ticket}، و{ahead}، وهنكون معاكي خلال حوالي {eta_minutes}'],
            'queue_night' => ['title' => 'الطابور: خارج مواعيد العمل', 'body' => 'شكراً لرسالتك، إحنا خارج مواعيد العمل دلوقتي. رقم تذكرتك #{ticket} وهنكلمك أول ما نفتح الساعة {opening} بالترتيب. من فضلك ما تبعتيش رسايل تانية عشان الدور ما يتأثرش.'],
            'queue_left_5' => ['title' => 'الطابور: باقي 5 دقايق', 'body' => 'باقي حوالي 5 دقايق وهتكوني مع الموظفة'],
            'queue_left_3' => ['title' => 'الطابور: باقي 3 دقايق', 'body' => 'باقي 3 دقايق تقريباً'],
            'queue_left_1' => ['title' => 'الطابور: باقي دقيقة', 'body' => 'دقيقة واحدة وهتكوني مع الموظفة'],
            'queue_apology' => ['title' => 'الطابور: اعتذار عن التأخير', 'body' => 'آسفين على التأخير، كل الموظفات مشغولات دلوقتي، هتتحولي أول ما حد يفضى.'],
            'queue_called' => ['title' => 'الطابور: جه دورك', 'body' => 'دورك جه، الموظفة {name} هترد عليكي دلوقتي.'],
            'queue_silence_warning' => ['title' => 'الطابور: تنبيه قبل القفل التلقائي', 'body' => 'لسه معانا يا فندم؟ المحادثة هتتقفل تلقائي بعد {minutes} لو مفيش رد، وتقدري تكتبيلنا في أي وقت وهنرجّعك بأولوية.'],
            'queue_auto_closed' => ['title' => 'الطابور: قفل تلقائي بعد سكوت', 'body' => 'اتقفلت المحادثة مؤقتاً، أول ما ترجعي ابعتي أي رسالة وهنرجّعك لنفس الموظفة بأولوية.'],
            'queue_returning' => ['title' => 'الطابور: رجعت بأولوية', 'body' => 'أهلاً بيكي تاني، بنرجّعك لنفس الموظفة بأولوية، رقم تذكرتك #{ticket} وهنكون معاكي خلال حوالي {eta_minutes}.'],
            'queue_reassigned' => ['title' => 'الطابور: تحويل لموظفة تانية', 'body' => 'هنكمّل معاكي مع موظفة تانية بأولوية، ثواني.'],
            'queue_review_ask' => ['title' => 'الطابور: طلب تقييم', 'body' => 'قيّمي خدمة {name} من 1 لـ5'],
            'queue_review_thanks' => ['title' => 'الطابور: شكر على التقييم', 'body' => 'شكراً لتقييمك'],
            'queue_case_opened' => ['title' => 'الطابور: كيس اتفتح', 'body' => 'فتحنالك طلب رقم {case_id}، وهيتم التواصل معاكي خلال يوم عمل.'],
            'queue_case_resolved' => ['title' => 'الطابور: كيس اتحل', 'body' => 'تم حل طلبك رقم {case_id}، شكراً لصبرك'],
            // Flow revision (2026-09-29): nobody who may take her is logged in yet — her ticket, no minutes.
            'queue_enqueued_no_eta' => ['title' => 'الطابور: دخلت الدور ومفيش تقدير', 'body' => 'رقم تذكرتك #{ticket}، الفريق بيبدأ دلوقتي وهنكون معاكي في أقرب وقت، خليكي معانا'],
            // Flow revision (2026-09-29) §3: she writes while waiting — her ticket and who is ahead, plus the time sentence when there is an estimate.
            'queue_position_update' => ['title' => 'الطابور: كتبت وهي مستنية', 'body' => 'لسه معاكي، رقم تذكرتك #{ticket}، و{ahead} {eta_sentence}'],
            'queue_eta_sentence' => ['title' => 'الطابور: جملة الوقت في تحديث الدور', 'body' => 'وهنكون معاكي خلال حوالي {minutes}'],
            // Flow revision (2026-09-29) §4.2: the moderator has not replied yet.
            'queue_agent_delay_apology' => ['title' => 'الطابور: اعتذار عن تأخير الموظفة', 'body' => 'معلش على التأخير، زميلتنا {agent} معاكي حالاً'],
        ];
    }
}
