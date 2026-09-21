<?php

/*
| رسائل الأخطاء اللي بتظهر للمستخدم: استثناءات، منع صلاحيات، وأخطاء الحقول.
| SetLocale بيظبط لغة صاحب الطلب قبل ما الكنترولر يشتغل، يعني __() هنا بترجع
| بلغة الشخص اللي هيقرا الرد.
|
| لازم نفس المفاتيح الموجودة في lang/en/errors.php بالظبط. أي رقم جاي من الكود
| بيتبعت كـ :parameter، مش متكتب جوه النص.
*/

return [

    'media' => [
        'unsupported' => 'نوع الملف ده مش مدعوم',
        'too_big' => 'الملف أكبر من المسموح (:max ميجا)',
        'platform_too_big' => 'الملف أكبر من المسموح على :platform (:max ميجا)',
        'platform_type' => 'النوع ده مش مدعوم على :platform',
        'instagram_file' => 'إنستجرام مش بيقبل ملفات — ابعت صورة أو فيديو أو صوت بس',
        'whatsapp_voice_unsupported' => 'صيغة الفويس مش مدعومة على واتساب',
        'not_claimable' => 'فيه ملف مش موجود أو اتبعت قبل كده — ارفعه تاني',
        'source_missing' => 'الملف الأصلي مش موجود',
        'copy_failed' => 'تعذر نسخ الملف المرفق',
    ],

    'send' => [
        'no_cards' => 'مفيش كروت تتبعت في الرسالة دي.',
        'not_supported_on_whatsapp' => 'النوع ده من الرسايل مش مدعوم على واتساب.',
        'media_attachment_missing' => 'المرفق مش موجود — ارفعه تاني.',
        'missing_waba_id' => 'حساب واتساب بيزنس مش متسجل — راجع إعدادات الربط.',
        'graph_unreachable' => 'مقدرناش نوصل لسيرفرات Meta — جرّب تاني.',
        'facebook_not_connected' => 'مفيش صفحة فيسبوك متوصلة.',
        'no_identity' => 'العميل مش متسجل على المنصة دي.',
        'send_failed' => 'الرسالة مبعتتش.',
    ],

    'inbox' => [
        'window_template_only' => 'نافذة الـ ٢٤ ساعة قفلت — مينفعش يتبعت غير تمبليت معتمد (حالة النافذة: template_only).',
        'window_closed' => 'نافذة الرد على المحادثة دي قافلة — الرسالة مش هتتبعت (حالة النافذة: closed).',
        'window_other' => 'الرسالة مينفعش تتبعت في حالة النافذة الحالية (حالة النافذة: :mode).',
        'retry_only_failed' => 'الرسايل الصادرة الفاشلة بس هي اللي ينفع تتبعت تاني.',
        'platform_not_allowed' => 'مش مسموح لك ترد على :platform.',
    ],

    'flows' => [
        'main_menu_must_stay_active' => 'القائمة الرئيسية لازم تفضل مفعّلة.',
        'draft_conflict' => 'حصل تعديل على المسودة من مكان تاني، لازم تحمّلي آخر نسخة الأول.',
        'publish_has_errors' => 'الفلو فيه أخطاء لازم تتصلح قبل النشر.',
        'restore_has_errors' => 'النسخة دي فيها أخطاء ومينفعش تترجع.',
        'sandbox_failed' => 'حصلت مشكلة في تجربة الفلو، جرّب تبدأ من جديد',
    ],

    'knowledge' => [
        'core_entry_undeletable' => 'المعلومة الأساسية دي مينفعش تتمسح — ممكن توقفيها بس',
    ],

    'learning' => [
        'review_failed' => 'المراجعة فشلت: :reason',
        'unknown_error' => 'خطأ غير معروف',
        'apply_failed' => 'التطبيق فشل: :reason',
        'suggestion_already_decided' => 'الاقتراح ده اتقرر فيه قبل كده.',
    ],

    'channels' => [
        'instagram_not_linked' => 'الحساب ده لسه مش مربوط بصفحة فيسبوك.',
        'instagram_subscribe_note' => 'تم تسجيل صفحة فيسبوك المربوطة. متنساش تفعّل حقول messages, comments مرة واحدة من App Dashboard ← Webhooks ← Instagram (التفاصيل في README §6 أو docs/deploy/cloudways-staging.md)، وإلا رسائل وتعليقات إنستجرام مش هتوصل.',
    ],

    'replies' => [
        'attachments_max' => 'أقصى عدد :max مرفقات للرد',
        'shortcut_taken' => 'الاختصار ده مستخدم قبل كده',
    ],

    'shopify' => [
        'import_running' => 'فيه استيراد شغال بالفعل — استنى لما يخلص',
        'no_integration' => 'مفيش ربط بـ Shopify متسجل',
        'orders_range_required' => 'مزامنة الطلبات محتاجة تاريخ من وإلى',
        'orders_range_max' => 'أقصى مدى للمزامنة سنة واحدة (:days يوم)',
    ],

    'bot' => [
        'ai_admin_only' => 'إعدادات الذكاء الاصطناعي الأدمن بس هو اللي يغيّرها.',
        'burst_max_wait_attribute' => 'أقصى مدة انتظار لتجميع الرسايل بالثواني',
    ],

    'users' => [
        'cannot_remove_own_admin' => 'مينفعش تشيل صلاحية الأدمن من نفسك.',
        'cannot_deactivate_self' => 'مينفعش توقف حسابك بنفسك.',
    ],

    'customers' => [
        'cannot_merge_into_itself' => 'مينفعش تدمج العميل في نفسه.',
    ],

    'orders' => [
        'cancel_fulfilled' => 'لا يمكن إلغاء طلب اتشحن كله أو جزء منه.',
        'idempotency_conflict' => 'مفتاح الطلب ده مستخدم في طلب تاني — افتح نموذج الطلب من جديد.',
        'only_awaiting_payment_can_be_paid' => 'الطلبات اللي مستنية دفع بس هي اللي ينفع تتدفع (الحالة: :status).',
        'shipment_cannot_advance' => 'الشحنة اللي حالتها :status مينفعش تتنقل للحالة اللي بعدها.',
    ],

    'auth' => [
        'reset_link_sent' => 'لو الحساب موجود هيوصله لينك لتغيير كلمة السر.',
        'account_inactive' => 'حسابك موقوف.',
    ],

    'roles' => [
        'requires' => 'العملية دي محتاجة صلاحية :role.',
        'names' => [
            'moderator' => 'مشرف',
            'supervisor' => 'مشرف عام',
            'admin' => 'أدمن',
        ],
    ],

    'reports' => [
        'date_range_invalid' => 'تاريخ النهاية لازم يكون في نفس يوم البداية أو بعده.',
    ],

];
