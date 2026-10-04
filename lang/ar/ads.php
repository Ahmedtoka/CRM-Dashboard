<?php

return [
    'assignment_before_open' => 'تاريخ البداية قبل بداية التسكين الحالي.',
    'assignment_overlaps' => 'تاريخ البداية بيتداخل مع تسكين سابق.',
    'unassigned' => 'غير مسند',
    'recommendation' => [
        'winner' => 'زوّد الميزانية 20–30٪ بالتدريج',
        'promising' => 'سيبه يجمع داتا ٣ أيام كمان',
        'loser' => 'وقّفه أو غيّر الكرييتف',
        'neutral' => 'راقبه',
    ],
    'flash' => [
        'stopped' => 'اتوقف.',
        'resumed' => 'اشتغل تاني.',
        'connected' => 'اتوصل. لقينا :count حساب إعلاني، وآخر 90 يوم بيتحمّلوا في الخلفية.',
        'saved' => 'اتحفظ.',
        'test_ok' => 'الاتصال شغال.',
        'sync_queued' => 'بدأت المزامنة.',
        'deleted' => 'اتمسح.',
        'archived' => 'الربط اتوقف ومش هيتسحب منه تاني. أرقامه القديمة فاضلة في التقارير.',
        'assigned' => 'اتحفظ التسكين.',
        'buyer_archived' => 'الميديا باير ليه تاريخ حسابات، فاتأرشف بدل ما يتمسح.',
    ],
    'errors' => [
        'out_of_scope' => 'مش مسموح لك تغيّر الإعلانات على الحساب ده.',
        'bad_request' => 'الطلب ده مش صحيح.',
        'not_found' => 'الحملة أو المجموعة أو الإعلان ده لسه مش في السيستم. اعمل مزامنة للحساب وجرّب تاني.',
        'rate_limited' => 'المنصة بتحدّ الطلبات دلوقتي. جرّب تاني بعد كام دقيقة.',
        'already' => 'هو أصلاً على الحالة دي. اعمل مزامنة للحساب لو شايفها غلط.',
        'failed' => 'المنصة مقبلتش التغيير.',
    ],
    'credentials' => [
        'access_token' => 'توكن الوصول',
        'advertiser_ids' => 'أرقام المُعلنين (مفصولة بفاصلة)',
        'developer_token' => 'توكن المطوّر',
        'client_id' => 'Client ID',
        'client_secret' => 'Client secret',
        'refresh_token' => 'Refresh token',
        'login_customer_id' => 'رقم حساب المدير (اختياري)',
    ],
    'materials' => [
        'file_type' => 'الملف :name مش مدعوم. المسموح صور (JPG, PNG, WebP, GIF) وفيديو (MP4, MOV, WebM).',
        'file_too_big' => 'الملف :name أكبر من الحد المسموح (:mb ميجا).',
        'file_upload_failed' => 'فشل رفع الملف. جرّب تاني.',
        'bad_link' => 'اللينك ده مش صحيح. لازم يبدأ بـ http أو https.',
        'status' => ['not_started' => 'لسه مبدأش', 'activated' => 'شغّال', 'done' => 'خلص'],
        'stock' => ['in' => 'متوفر', 'out' => 'خلص من المخزون', 'none' => 'من غير منتج'],
        'csv' => [
            'title' => 'العنوان', 'created' => 'تاريخ الإنشاء', 'product' => 'المنتج', 'collections' => 'المجموعات', 'types' => 'النوع',
            'status' => 'الحالة', 'stock' => 'المخزون', 'drive_links' => 'لينكات درايف', 'ads' => 'الإعلانات المربوطة', 'spend' => 'الصرف', 'roas' => 'العائد على الإعلان',
        ],
        'stock_csv' => [
            'title' => 'المادة', 'product' => 'المنتج', 'variants' => 'المقاسات والألوان', 'price' => 'السعر', 'quantity' => 'الكمية', 'collections' => 'المجموعات', 'availability' => 'متوفر', 'yes' => 'أيوه', 'no' => 'لأ',
        ],
    ],
];
