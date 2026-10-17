<?php

// The Shopify app's embedded page inside Shopify admin (resources/views/shopify/app.blade.php).
return [
    'title' => 'Le Voile CRM',
    'connecting' => 'بنربط المتجر بالـ CRM…',
    'connected' => 'المتجر متوصل بالـ CRM',
    'not_connected' => 'المتجر مش متوصل',
    'shop' => 'المتجر',
    'status' => 'الحالة',
    'mode' => 'طريقة الاتصال',
    'mode_app' => 'أبلكيشن Shopify',
    'mode_custom_token' => 'الاتصال اليدوي',
    'token_valid_until' => 'التوكن صالح لحد',
    'token_note' => 'بيتجدد لوحده كل ساعة',
    'last_webhook' => 'آخر تحديث وصل من Shopify',
    'never' => 'لسه مفيش',
    'open_crm' => 'افتح الـ CRM',
    'open_crm_note' => 'الـ CRM بيفتح في تاب جديدة، مش جوه Shopify.',
    'errors' => [
        'invalid_token' => 'Shopify ما بعتش تصريح صالح. اقفل الصفحة وافتح الأبلكيشن تاني من Shopify admin.',
        'shop_not_allowed' => 'الأبلكيشن ده مش للمتجر ده.',
        'other_store' => 'الـ CRM متوصل بمتجر تاني. مينفعش يتوصل بمتجرين.',
        'exchange_failed' => 'Shopify ما ردش على طلب التوكن. جرّب تاني بعد دقيقة.',
        'generic' => 'حصلت مشكلة. جرّب تاني بعد دقيقة.',
    ],
];
