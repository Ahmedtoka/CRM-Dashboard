<?php

/**
 * Handover-queue lines staff read in the thread. Written into the conversation in Arabic (like
 * every other system line there) at the moment it happens.
 */
return [
    'system' => [
        'no_reply_handoff' => 'اتحوّلت لزميلة تانية لأن :agent ما ردّتش خلال :minutes دقايق',
    ],
    // Points ledger reasons (queue Part 2), rendered in the viewer's language from score_events.reason_key.
    'points' => [
        'base_inquiry' => 'استفسار اتجاوب · دور #:ticket',
        'base_problem' => 'مشكلة اتحلّت · دور #:ticket',
        'base_case' => 'فتحت طلب · دور #:ticket',
        'base_auto' => 'اتقفلت لوحدها من السكوت · دور #:ticket',
        'base_escalation' => 'صعّدتها للّيدر · دور #:ticket',
        'speed_fast' => 'ردّت بسرعة (:time) · دور #:ticket',
        'speed_ok' => 'ردّت في وقت كويس (:time) · دور #:ticket',
        'no_reply' => 'ما ردّتش واتحوّلت لزميلة · دور #:ticket',
        'review' => 'تقييم العميلة :stars من 5 · دور #:ticket',
        'case_in_time' => 'طلب #:case اتحل في معاده',
        'case_late' => 'طلب #:case اتحل متأخر',
        'case_reopened' => 'طلب #:case رجع مفتوح',
        'qa' => 'تقييم الجودة :score من 5 · دور #:ticket',
        'qa_replaced' => 'تقييم جودة اتعدّل · دور #:ticket',
        'reversed' => 'اتلغت',
        'capped' => '(فوق السقف :cap)',
    ],
    // The queue report's CSV header (queue Part 2).
    'report' => [
        'moderator' => 'الموظفة',
        'received' => 'استلمت',
        'closed' => ['inquiry' => 'استفسار', 'problem' => 'مشكلة', 'case' => 'طلب', 'auto' => 'تلقائي', 'escalation' => 'تصعيد', 'no_reply' => 'ما ردّتش', 'other' => 'تاني'],
        'first_reply_avg' => 'متوسط أول رد (ثانية)',
        'handle_avg' => 'متوسط مدة الشباك (ثانية)',
        'sla_pct' => 'نسبة الـ SLA',
        'rating_avg' => 'متوسط التقييم',
        'rating_count' => 'عدد التقييمات',
        'qa_avg' => 'متوسط الجودة',
        'qa_count' => 'عدد تقييمات الجودة',
        'cases_resolved' => 'طلبات اتحلّت',
        'points' => ['base' => 'نقط الشبابيك', 'speed' => 'نقط السرعة', 'case' => 'نقط الطلبات', 'review' => 'نقط التقييم', 'qa' => 'نقط الجودة', 'no_reply' => 'خصم عدم الرد'],
        'total' => 'إجمالي النقط',
    ],
];
