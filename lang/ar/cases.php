<?php

/*
 * Case summaries, alerts and handover labels (the "cases & handover" cluster).
 *
 * Namespaces:
 *   cases.types.*        SupportCase::typeLabel()
 *   cases.priority.*     case priority + handover priority
 *   cases.header         the summary header line
 *   cases.sections.*     the section titles
 *   cases.customer.*     the customer block
 *   cases.order.*        the order block
 *   cases.items.*        the picked pieces
 *   cases.fields.*       "label: value" field labels in the request block
 *   cases.exchange.*     the replacement product lines and the exchange note
 *   cases.edit.*         the cancel/edit note
 *   cases.attachments.*  the attachments block
 *   cases.team_action.*  what the team should do
 *   cases.policy.*       stored policy-note codes (see CaseRecorder)
 *   cases.handover.*     the bot handover reason/category labels
 */

return [
    'currency' => 'ج.م',

    'types' => [
        'return_exchange' => 'مرتجع/استبدال',
        'return' => 'مرتجع',
        'exchange' => 'استبدال',
        'complaint' => 'شكوى',
        'cancel_edit' => 'إلغاء/تعديل أوردر',
        'delivery_followup' => 'متابعة شحن',
    ],

    'priority' => [
        'low' => 'منخفضة',
        'medium' => 'متوسطة',
        'high' => 'عالية',
    ],

    'header' => '📋 حالة #:id — :type — أولوية :priority',

    'sections' => [
        'customer' => 'العميل',
        'order' => 'الأوردر',
        'items' => 'القطع المطلوبة',
        'request' => 'الطلب',
        'attachments' => 'المرفقات',
        'alerts' => 'تنبيهات',
        'team_action' => 'المطلوب من الفريق',
    ],

    'no_alerts' => 'مفيش',

    'customer' => [
        'unknown' => 'غير معروف',
    ],

    'order' => [
        'numbered' => 'الأوردر #:number',
        'not_found' => 'الأوردر: :ref (مش لاقيينه في السيستم)',
        'placed_on' => 'بتاريخ :date',
        'products' => 'المنتجات: :list',
        'separator' => '، ',
        'more_one' => 'ومنتج تاني',
        'more_many' => 'و :count منتجات تانية',
    ],

    'items' => [
        'exchange_only' => '(استبدال بس)',
    ],

    'fields' => [
        'kind' => 'الطلب',
        'reason' => 'السبب',
        'request' => 'المطلوب',
        'complaint_type' => 'النوع',
        'branch' => 'الفرع',
        'visit_date' => 'تاريخ الزيارة',
        'description' => 'التفاصيل',
        'cancel_reason' => 'سبب الإلغاء',
        'edit_kind' => 'نوع التعديل',
        'new_address' => 'العنوان الجديد',
        'new_phone' => 'الموبايل الجديد',
        'edit_details' => 'التعديل',
        'delivery_status' => 'حالة الشحن',
    ],

    'exchange' => [
        'replacement' => 'البديل',
        'link' => 'اللينك',
        'typed' => 'البديل: مش متحدد — العميلة كتبت «:text»',
        'photo' => 'البديل: العميلة بعتت صورة للمنتج',
        'unknown' => 'البديل: مش متحدد',
        'note_item' => 'القطعة',
        'note' => 'طلب استبدال: :items ← :product',
        'note_typed' => 'طلب استبدال: :items ← المنتج مش متحدد، العميلة كتبت: «:text»',
        'note_photo' => 'طلب استبدال: :items ← العميلة بعتت صورة للمنتج البديل (في الصور)',
        'note_unknown' => 'طلب استبدال: :items ← المنتج البديل مش متحدد',
    ],

    'edit' => [
        'new_address' => '📍 العنوان الجديد: :address',
        'new_phone' => '📞 الموبايل الجديد: :phone',
        'note_header' => '✏️ تعديلات مطلوبة على :order',
        'note_order_numbered' => 'أوردر #:number',
        'note_order_any' => 'الأوردر',
    ],

    'attachments' => [
        'item_photo' => 'صورة القطعة',
        'no_photo' => '— (مبعتتش صورة)',
        'replacement_photo' => 'صورة المنتج البديل',
        'change_photo' => 'صورة للمنتج البديل',
        'customer_photos' => 'صور من العميلة',
        'product_photo' => 'صورة المنتج',
        'defect_photo' => 'صورة العيب',
    ],

    'team_action' => [
        'return' => 'مراجعة القطعة وترتيب المندوب لاستلام المرتجع وإبلاغ العميلة بموعده',
        'exchange' => 'التأكد من توفر المنتج البديل ومقاسه، والتواصل مع العميلة لتأكيد الاستبدال والإرسال',
        'return_exchange' => [
            'refund' => 'التواصل مع العميلة وترتيب استلام القطعة ورد المبلغ',
            'exchange' => 'التواصل مع العميلة وترتيب استبدال القطعة',
            'default' => 'التواصل مع العميلة ومراجعة طلب المرتجع',
        ],
        'complaint' => 'التواصل مع العميل ومتابعة الشكوى وحلها',
        'cancel_edit' => [
            'editable' => [
                'cancel' => 'إلغاء الأوردر قبل ما يتشحن وتأكيد الإلغاء مع العميلة',
                'edit' => 'تنفيذ التعديلات على الأوردر قبل ما يتشحن وتأكيدها مع العميلة',
                'default' => 'مراجعة الأوردر وتنفيذ طلب العميلة قبل ما يتشحن',
            ],
            'window' => [
                'cancel' => 'مراجعة الأوردر وإلغاؤه لو لسه في المهلة',
                'edit' => 'مراجعة الأوردر وتنفيذ التعديل المطلوب لو لسه في المهلة',
                'default' => 'مراجعة الأوردر وتنفيذ طلب العميلة لو لسه في المهلة',
            ],
        ],
        'delivery_followup' => 'متابعة الشحنة مع شركة الشحن والرد على العميل',
        'default' => 'مراجعة الحالة والتواصل مع العميل',
    ],

    'policy' => [
        'cancel_window_open' => 'الأوردر لسه متشحنش وقت الطلب — اتأكدوا قبل ما يخرج من الشركة',
        'cancel_window_left' => 'باقي على مهلة الإلغاء/التعديل: :minutes دقيقة',
        'cancel_window_over' => 'انتهت مهلة الإلغاء/التعديل',
    ],

    'handover' => [
        'categories' => [
            'unclear' => 'مش واضح',
            'repeated' => 'سؤال متكرر',
            'angry_or_urgent' => 'غضب/إلحاح',
            'no_script' => 'لا يوجد رد جاهز',
            'ai_error' => 'خطأ في البوت',
            'window_closed' => 'نافذة الرد مقفولة',
            'order_not_found' => 'الأوردر مش موجود',
            'delayed_order' => 'الأوردر متأخر',
            'order_hold' => 'الأوردر متوقف للمراجعة',
            'order_returned' => 'الأوردر مرتجع',
            'failed_delivery_attempt' => 'محاولة توصيل فشلت',
            'order_details_missing' => 'بيانات الأوردر ناقصة',
            'new_order' => 'طلب أوردر جديد',
            'order_verification_failed' => 'مقدرناش نتأكد إن الأوردر بتاعها',
        ],
        'reasons' => [
            'purchase' => 'العميلة عايزة تطلب',
            'contact_details' => 'العميلة بعتت عنوان أو رقم تليفون',
            'size_recommendation' => 'العميلة بتسأل عن مقاسها',
            'complaint' => 'شكوى',
            'negative_sentiment' => 'العميلة متضايقة',
            'order_status' => 'سؤال عن أوردر قائم',
            'ai_low_confidence' => 'البوت مش متأكد من الرد',
            'keyword' => 'طلبت تكلم موظف',
            'max_turns' => 'البوت رد كتير من غير ما يخلص',
            'ai_guard' => 'الرد كان فيه أرقام مش موجودة في البيانات',
            'ai_error' => 'خطأ في البوت',
            'window_closed' => 'نافذة الرد مقفولة',
            'no_rule' => 'مفيش رد مناسب',
            'rule' => 'قاعدة تحويل',
            'no_product_match' => 'المنتج مش لاقيه في الكتالوج',
            'intent' => 'طلب العميل',
        ],
    ],
];
