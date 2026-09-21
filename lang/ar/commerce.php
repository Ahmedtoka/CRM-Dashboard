<?php

/**
 * Order validation / permission messages thrown straight back to the staff
 * member who made the request (ValidationException, AuthorizationException),
 * so they resolve in that viewer's locale.
 *
 * Nothing that ends up in `Order.last_error`, in an activity log or in the
 * conversation thread belongs here — that content is persisted by a queue
 * worker running in the default locale.
 */
return [
    'order' => [
        'creation_disabled' => 'إنشاء الطلبات من المحادثات متوقف حاليًا من إعدادات Shopify.',
        'items_required' => 'لازم صنف واحد على الأقل.',
        'variant_not_found' => 'المقاس/اللون رقم :id مش موجود.',
        'retry_forbidden' => 'اللي عمل الأوردر أو المشرف بس اللي يقدر يعيد المحاولة.',
        'retry_not_failed' => 'الأوردرات الفاشلة بس اللي تقدر تعيد محاولتها (الحالة: :status).',
        'cancel_forbidden' => 'المشرف أو الأدمن بس اللي يقدر يلغي أوردر.',
    ],

    'discount' => [
        'supervisor_only' => 'المشرف أو الأدمن بس اللي يقدر يعمل خصم.',
        'reason_required' => 'سبب الخصم مطلوب.',
        'invalid_value' => 'قيمة الخصم غير صحيحة.',
        'invalid_type' => 'نوع الخصم لازم يكون مبلغ ثابت أو نسبة.',
    ],

    'shipping' => [
        'address_not_customers' => 'العنوان المختار مش تبع العميل ده.',
        'rate_unavailable' => 'طريقة الشحن المختارة مش متاحة للمحافظة دي أو لقيمة الطلب.',
    ],
];
