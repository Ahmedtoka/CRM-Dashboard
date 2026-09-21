<?php

/*
|--------------------------------------------------------------------------
| Arabic validation messages
|--------------------------------------------------------------------------
|
| Laravel ships English defaults inside the framework, so before this file
| existed every form error in the dashboard came back in English even when
| the staff member was working in Arabic. The wording is the plain Egyptian
| register the rest of the interface uses, not classical Arabic.
|
| `attributes` covers the fields a staff member can actually see an error
| for in the dashboard forms; anything not listed falls back to the field
| name, which is what Laravel does anyway.
|
*/

return [

    'accepted' => 'لازم توافق على :attribute.',
    'accepted_if' => 'لازم توافق على :attribute لما :other يكون :value.',
    'active_url' => 'حقل :attribute لازم يكون رابط صحيح.',
    'after' => 'حقل :attribute لازم يكون تاريخ بعد :date.',
    'after_or_equal' => 'حقل :attribute لازم يكون تاريخ :date أو بعده.',
    'alpha' => 'حقل :attribute لازم يكون حروف بس.',
    'alpha_dash' => 'حقل :attribute لازم يكون حروف وأرقام وشرط بس.',
    'alpha_num' => 'حقل :attribute لازم يكون حروف وأرقام بس.',
    'any_of' => 'حقل :attribute مش صحيح.',
    'array' => 'حقل :attribute لازم يكون قائمة.',
    'ascii' => 'حقل :attribute لازم يكون حروف وأرقام ورموز إنجليزي بس.',
    'before' => 'حقل :attribute لازم يكون تاريخ قبل :date.',
    'before_or_equal' => 'حقل :attribute لازم يكون تاريخ :date أو قبله.',
    'between' => [
        'array' => 'حقل :attribute لازم يكون بين :min و :max عنصر.',
        'file' => 'حقل :attribute لازم يكون بين :min و :max كيلوبايت.',
        'numeric' => 'حقل :attribute لازم يكون بين :min و :max.',
        'string' => 'حقل :attribute لازم يكون بين :min و :max حرف.',
    ],
    'boolean' => 'حقل :attribute لازم يكون بنعم أو لأ.',
    'can' => 'حقل :attribute فيه قيمة مش مسموح بيها.',
    'confirmed' => 'تأكيد :attribute مش مطابق.',
    'contains' => 'حقل :attribute ناقصه قيمة مطلوبة.',
    'current_password' => 'كلمة السر غلط.',
    'date' => 'حقل :attribute لازم يكون تاريخ صحيح.',
    'date_equals' => 'حقل :attribute لازم يكون تاريخ يساوي :date.',
    'date_format' => 'حقل :attribute لازم يكون بالشكل :format.',
    'decimal' => 'حقل :attribute لازم يكون فيه :decimal رقم عشري.',
    'declined' => 'لازم ترفض :attribute.',
    'declined_if' => 'لازم ترفض :attribute لما :other يكون :value.',
    'different' => 'حقل :attribute و :other لازم يكونوا مختلفين.',
    'digits' => 'حقل :attribute لازم يكون :digits رقم.',
    'digits_between' => 'حقل :attribute لازم يكون بين :min و :max رقم.',
    'dimensions' => 'مقاسات صورة :attribute مش مظبوطة.',
    'distinct' => 'حقل :attribute فيه قيمة متكررة.',
    'doesnt_contain' => 'حقل :attribute ميحتويش على أي من دول: :values.',
    'doesnt_end_with' => 'حقل :attribute مينفعش ينتهي بواحد من دول: :values.',
    'doesnt_start_with' => 'حقل :attribute مينفعش يبدأ بواحد من دول: :values.',
    'email' => 'حقل :attribute لازم يكون بريد إلكتروني صحيح.',
    'encoding' => 'حقل :attribute لازم يكون بترميز :encoding.',
    'ends_with' => 'حقل :attribute لازم ينتهي بواحد من دول: :values.',
    'enum' => 'القيمة المختارة في حقل :attribute مش صحيحة.',
    'exists' => 'القيمة المختارة في حقل :attribute مش صحيحة.',
    'extensions' => 'حقل :attribute لازم يكون بامتداد من دول: :values.',
    'file' => 'حقل :attribute لازم يكون ملف.',
    'filled' => 'حقل :attribute لازم يتملى.',
    'gt' => [
        'array' => 'حقل :attribute لازم يكون فيه أكتر من :value عنصر.',
        'file' => 'حقل :attribute لازم يكون أكبر من :value كيلوبايت.',
        'numeric' => 'حقل :attribute لازم يكون أكبر من :value.',
        'string' => 'حقل :attribute لازم يكون أكتر من :value حرف.',
    ],
    'gte' => [
        'array' => 'حقل :attribute لازم يكون فيه :value عنصر أو أكتر.',
        'file' => 'حقل :attribute لازم يكون :value كيلوبايت أو أكبر.',
        'numeric' => 'حقل :attribute لازم يكون :value أو أكبر.',
        'string' => 'حقل :attribute لازم يكون :value حرف أو أكتر.',
    ],
    'hex_color' => 'حقل :attribute لازم يكون لون هيكس صحيح.',
    'image' => 'حقل :attribute لازم يكون صورة.',
    'in' => 'القيمة المختارة في حقل :attribute مش صحيحة.',
    'in_array' => 'حقل :attribute لازم يكون موجود في :other.',
    'in_array_keys' => 'حقل :attribute لازم يحتوي على واحد على الأقل من: :values.',
    'integer' => 'حقل :attribute لازم يكون رقم صحيح.',
    'ip' => 'حقل :attribute لازم يكون عنوان IP صحيح.',
    'ipv4' => 'حقل :attribute لازم يكون عنوان IPv4 صحيح.',
    'ipv6' => 'حقل :attribute لازم يكون عنوان IPv6 صحيح.',
    'json' => 'حقل :attribute لازم يكون JSON صحيح.',
    'list' => 'حقل :attribute لازم يكون قائمة.',
    'lowercase' => 'حقل :attribute لازم يكون حروف صغيرة.',
    'lt' => [
        'array' => 'حقل :attribute لازم يكون فيه أقل من :value عنصر.',
        'file' => 'حقل :attribute لازم يكون أقل من :value كيلوبايت.',
        'numeric' => 'حقل :attribute لازم يكون أقل من :value.',
        'string' => 'حقل :attribute لازم يكون أقل من :value حرف.',
    ],
    'lte' => [
        'array' => 'حقل :attribute مينفعش يكون فيه أكتر من :value عنصر.',
        'file' => 'حقل :attribute لازم يكون :value كيلوبايت أو أقل.',
        'numeric' => 'حقل :attribute لازم يكون :value أو أقل.',
        'string' => 'حقل :attribute لازم يكون :value حرف أو أقل.',
    ],
    'mac_address' => 'حقل :attribute لازم يكون عنوان MAC صحيح.',
    'max' => [
        'array' => 'حقل :attribute مينفعش يكون فيه أكتر من :max عنصر.',
        'file' => 'حقل :attribute مينفعش يكون أكبر من :max كيلوبايت.',
        'numeric' => 'حقل :attribute مينفعش يكون أكبر من :max.',
        'string' => 'حقل :attribute مينفعش يكون أطول من :max حرف.',
    ],
    'max_digits' => 'حقل :attribute مينفعش يكون فيه أكتر من :max رقم.',
    'mimes' => 'حقل :attribute لازم يكون ملف من نوع: :values.',
    'mimetypes' => 'حقل :attribute لازم يكون ملف من نوع: :values.',
    'min' => [
        'array' => 'حقل :attribute لازم يكون فيه :min عنصر على الأقل.',
        'file' => 'حقل :attribute لازم يكون :min كيلوبايت على الأقل.',
        'numeric' => 'حقل :attribute لازم يكون :min على الأقل.',
        'string' => 'حقل :attribute لازم يكون :min حرف على الأقل.',
    ],
    'min_digits' => 'حقل :attribute لازم يكون فيه :min رقم على الأقل.',
    'missing' => 'حقل :attribute لازم ميتبعتش.',
    'missing_if' => 'حقل :attribute لازم ميتبعتش لما :other يكون :value.',
    'missing_unless' => 'حقل :attribute لازم ميتبعتش إلا لما :other يكون :value.',
    'missing_with' => 'حقل :attribute لازم ميتبعتش مع :values.',
    'missing_with_all' => 'حقل :attribute لازم ميتبعتش مع :values.',
    'multiple_of' => 'حقل :attribute لازم يكون من مضاعفات :value.',
    'not_in' => 'القيمة المختارة في حقل :attribute مش صحيحة.',
    'not_regex' => 'شكل :attribute مش صحيح.',
    'numeric' => 'حقل :attribute لازم يكون رقم.',
    'password' => [
        'letters' => 'حقل :attribute لازم يكون فيه حرف واحد على الأقل.',
        'mixed' => 'حقل :attribute لازم يكون فيه حرف كبير وحرف صغير على الأقل.',
        'numbers' => 'حقل :attribute لازم يكون فيه رقم واحد على الأقل.',
        'symbols' => 'حقل :attribute لازم يكون فيه رمز واحد على الأقل.',
        'uncompromised' => ':attribute ده ظهر في تسريب بيانات قبل كده، اختار واحدة تانية.',
    ],
    'present' => 'حقل :attribute لازم يكون موجود.',
    'present_if' => 'حقل :attribute لازم يكون موجود لما :other يكون :value.',
    'present_unless' => 'حقل :attribute لازم يكون موجود إلا لما :other يكون :value.',
    'present_with' => 'حقل :attribute لازم يكون موجود مع :values.',
    'present_with_all' => 'حقل :attribute لازم يكون موجود مع :values.',
    'prohibited' => 'حقل :attribute مش مسموح بيه.',
    'prohibited_if' => 'حقل :attribute مش مسموح بيه لما :other يكون :value.',
    'prohibited_if_accepted' => 'حقل :attribute مش مسموح بيه لما :other يكون مقبول.',
    'prohibited_if_declined' => 'حقل :attribute مش مسموح بيه لما :other يكون مرفوض.',
    'prohibited_unless' => 'حقل :attribute مش مسموح بيه إلا لما :other يكون في :values.',
    'prohibits' => 'حقل :attribute بيمنع :other إنه يكون موجود.',
    'regex' => 'شكل :attribute مش صحيح.',
    'required' => 'حقل :attribute مطلوب.',
    'required_array_keys' => 'حقل :attribute لازم يحتوي على: :values.',
    'required_if' => 'حقل :attribute مطلوب لما :other يكون :value.',
    'required_if_accepted' => 'حقل :attribute مطلوب لما :other يكون مقبول.',
    'required_if_declined' => 'حقل :attribute مطلوب لما :other يكون مرفوض.',
    'required_unless' => 'حقل :attribute مطلوب إلا لما :other يكون في :values.',
    'required_with' => 'حقل :attribute مطلوب مع :values.',
    'required_with_all' => 'حقل :attribute مطلوب مع :values.',
    'required_without' => 'حقل :attribute مطلوب من غير :values.',
    'required_without_all' => 'حقل :attribute مطلوب لما مفيش أي من :values.',
    'same' => 'حقل :attribute لازم يطابق :other.',
    'size' => [
        'array' => 'حقل :attribute لازم يكون فيه :size عنصر.',
        'file' => 'حقل :attribute لازم يكون :size كيلوبايت.',
        'numeric' => 'حقل :attribute لازم يكون :size.',
        'string' => 'حقل :attribute لازم يكون :size حرف.',
    ],
    'starts_with' => 'حقل :attribute لازم يبدأ بواحد من دول: :values.',
    'string' => 'حقل :attribute لازم يكون نص.',
    'timezone' => 'حقل :attribute لازم يكون منطقة زمنية صحيحة.',
    'ulid' => 'حقل :attribute لازم يكون ULID صحيح.',
    'unique' => ':attribute مستخدم قبل كده.',
    'uploaded' => 'رفع :attribute فشل.',
    'uppercase' => 'حقل :attribute لازم يكون حروف كبيرة.',
    'url' => 'حقل :attribute لازم يكون رابط صحيح.',
    'uuid' => 'حقل :attribute لازم يكون UUID صحيح.',

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    'attributes' => [
        // account
        'name' => 'الاسم',
        'email' => 'البريد الإلكتروني',
        'password' => 'كلمة السر',
        'password_confirmation' => 'تأكيد كلمة السر',
        'current_password' => 'كلمة السر الحالية',
        'locale' => 'لغة الواجهة',
        'role' => 'الصلاحية',
        'color' => 'اللون',
        'platforms' => 'المنصات',
        'is_active' => 'مفعّل',
        'remember' => 'تذكرني',

        // branches
        'governorate' => 'المحافظة',
        'area_key' => 'المنطقة',
        'area_ar' => 'اسم المنطقة بالعربي',
        'area_en' => 'اسم المنطقة بالإنجليزي',
        'address' => 'العنوان',
        'phone' => 'الموبايل',
        'map_url' => 'رابط الخريطة',
        'hours' => 'مواعيد العمل',
        'aliases' => 'أسماء تانية',

        // cities & shipping
        'name_ar' => 'الاسم بالعربي',
        'name_en' => 'الاسم بالإنجليزي',
        'shipping_fee' => 'مصاريف الشحن',
        'default_shipping_fee' => 'مصاريف الشحن الافتراضية',

        // saved replies
        'shortcut' => 'الاختصار',
        'title' => 'العنوان',
        'body' => 'النص',
        'category_id' => 'التصنيف',
        'scope' => 'النطاق',
        'attachment' => 'المرفق',
        'attachment_ids' => 'المرفقات',

        // orders
        'items' => 'القطع',
        'discount' => 'الخصم',
        'discount_type' => 'نوع الخصم',
        'discount_reason' => 'سبب الخصم',
        'address_id' => 'العنوان',
        'shipping_option' => 'طريقة الشحن',
        'payment_method' => 'طريقة الدفع',
        'note' => 'ملاحظة',

        // shopify
        'shop_domain' => 'دومين المتجر',
        'access_token' => 'توكن الدخول',
        'api_secret' => 'الـ API secret',
        'stuck_after_days' => 'الأيام قبل اعتبار الأوردر متعطل',

        // bot
        'max_bot_turns' => 'أقصى عدد ردود للبوت',
        'burst_wait_seconds' => 'الانتظار بعد آخر رسالة',
        'burst_max_wait_seconds' => 'أقصى انتظار',
        'typing_ms_per_char' => 'سرعة الكتابة',
        'comment_reply_delay_min' => 'أقل تأخير للرد على التعليقات',
        'comment_reply_delay_max' => 'أقصى تأخير للرد على التعليقات',
        'min_confidence' => 'أقل ثقة للذكاء الاصطناعي',
        'system_prompt' => 'تعليمات الذكاء الاصطناعي',
        'handover_keywords' => 'كلمات التحويل لموظف',
        'spam_phrases' => 'عبارات السبام',
        'low_value_phrases' => 'العبارات قليلة القيمة',
        'allowed_link_domains' => 'المواقع المسموح بيها',
        'store_link' => 'لينك المتجر',
        'outside_hours_message' => 'رسالة خارج مواعيد العمل',

        // test links
        'label' => 'الاسم',
        'max_messages_per_session' => 'أقصى عدد رسايل للجلسة',
        'max_sessions' => 'أقصى عدد جلسات',

        // filters
        'from' => 'من تاريخ',
        'to' => 'إلى تاريخ',
        'q' => 'البحث',
        'file' => 'الملف',
    ],

];
