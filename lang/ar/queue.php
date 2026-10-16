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
];
