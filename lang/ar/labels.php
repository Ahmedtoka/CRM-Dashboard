<?php

/**
 * Display labels resolved at READ time (SetLocale has already switched the app
 * locale to the viewer's). Never store the result of one of these in a column —
 * that would freeze the writer's locale. Anything persisted keeps a stable code
 * and is translated through here when it is rendered.
 */
return [
    // Egyptian pound, as staff write it.
    'currency' => 'ج.م',

    'shipping' => [
        // ShippingQuote's fallback option when no Shopify zone rate matches.
        'default' => 'شحن',
    ],

    'test_session' => [
        // BotTestSession::label(); the first run shows the name on its own.
        'run' => ':name — الجلسة :n',
    ],

    // DeviceFamily::label(): only the non-brand values. iPhone/iPad/Android/Mac/
    // Windows/Linux are brand words and stay as they are in both locales.
    'device' => [
        'unknown' => 'غير معروف',
        'android_tablet' => 'تابلت أندرويد',
    ],

    // ConnectionHealthCheck::problemText(): the stored `problem:<code>` in
    // ChannelAccount.last_error. An unrecognised value (a raw Graph/Shopify
    // message, or a row written before the codes existed) renders unchanged.
    'channel_problem' => [
        'token_missing' => 'مفيش توكن محفوظ — اعمل إعادة ربط',
        'token_invalid' => 'التوكن مبقاش صالح — اعمل إعادة ربط',
        'missing_scopes' => 'التوكن ناقصه صلاحيات',
        'not_subscribed' => 'الويب هوك مش متسجل — الرسائل مش هتوصل',
        'missing_fields' => 'الويب هوك متسجل من غير حقل الرسائل',
        'instagram_unlinked' => 'حساب إنستجرام اتفصل عن صفحة فيسبوك',
        'instagram_changed' => 'صفحة فيسبوك بقت مربوطة بحساب إنستجرام تاني',
        'facebook_disconnected' => 'صفحة فيسبوك اللي إنستجرام بيستخدمها مش متوصلة',
    ],

    // ShipmentEvent.description values the CRM itself writes (carrier text is
    // passed through untouched). Keyed by the stored sentinel in OrderResource.
    'shipment_event' => [
        'created' => 'الشحنة اتعملت',
        'order_cancelled' => 'الأوردر اتلغى',
    ],

    'csv' => [
        'yes' => 'نعم',
        'no' => 'لا',

        'test_links' => [
            'link' => 'الرابط',
            'tester' => 'المختبِرة',
            'run' => 'رقم الجلسة',
            'started_at' => 'بدأت في',
            'duration_seconds' => 'المدة بالثواني',
            'messages_in' => 'رسائل واردة',
            'messages_out' => 'رسائل صادرة',
            'device' => 'الجهاز',
            'flows' => 'المسارات',
            'last_step' => 'آخر خطوة',
            'finished' => 'خلصت',
            'cases' => 'الحالات',
            'handovers' => 'التحويلات',
            'conversation_id' => 'رقم المحادثة',
        ],

        'quick_replies' => [
            'shortcut' => 'الاختصار',
            'title' => 'العنوان',
            'scope' => 'النطاق',
            'category' => 'التصنيف',
            'uses' => 'مرات الاستخدام',
            'users' => 'الموظفين',
            'platforms' => 'المنصات',
            'last_used_at' => 'آخر استخدام',
        ],
    ],
];
