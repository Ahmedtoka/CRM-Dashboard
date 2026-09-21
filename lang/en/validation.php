<?php

/*
|--------------------------------------------------------------------------
| English validation attribute names
|--------------------------------------------------------------------------
|
| Laravel already ships the English rule messages, so this file only names
| the fields — otherwise an error reads "The name_ar field is required."
| The keys mirror lang/ar/validation.php's `attributes` block.
|
*/

return [

    'attributes' => [
        // account
        'name' => 'name',
        'email' => 'email address',
        'password' => 'password',
        'password_confirmation' => 'password confirmation',
        'current_password' => 'current password',
        'locale' => 'interface language',
        'role' => 'role',
        'color' => 'colour',
        'platforms' => 'platforms',
        'is_active' => 'active',
        'remember' => 'remember me',

        // branches
        'governorate' => 'governorate',
        'area_key' => 'area',
        'area_ar' => 'area name in Arabic',
        'area_en' => 'area name in English',
        'address' => 'address',
        'phone' => 'phone',
        'map_url' => 'map link',
        'hours' => 'opening hours',
        'aliases' => 'other names',

        // cities & shipping
        'name_ar' => 'Arabic name',
        'name_en' => 'English name',
        'shipping_fee' => 'shipping fee',
        'default_shipping_fee' => 'default shipping fee',

        // saved replies
        'shortcut' => 'shortcut',
        'title' => 'title',
        'body' => 'body',
        'category_id' => 'category',
        'scope' => 'scope',
        'attachment' => 'attachment',
        'attachment_ids' => 'attachments',

        // orders
        'items' => 'items',
        'discount' => 'discount',
        'discount_type' => 'discount type',
        'discount_reason' => 'discount reason',
        'address_id' => 'address',
        'shipping_option' => 'shipping method',
        'payment_method' => 'payment method',
        'note' => 'note',

        // shopify
        'shop_domain' => 'store domain',
        'access_token' => 'access token',
        'api_secret' => 'API secret',
        'stuck_after_days' => 'days before an order is stuck',

        // bot
        'max_bot_turns' => 'max bot turns',
        'burst_wait_seconds' => 'wait after the last message',
        'burst_max_wait_seconds' => 'longest wait',
        'typing_ms_per_char' => 'typing speed',
        'comment_reply_delay_min' => 'minimum comment reply delay',
        'comment_reply_delay_max' => 'maximum comment reply delay',
        'min_confidence' => 'minimum AI confidence',
        'system_prompt' => 'AI system prompt',
        'handover_keywords' => 'handover keywords',
        'spam_phrases' => 'spam phrases',
        'low_value_phrases' => 'low-value phrases',
        'allowed_link_domains' => 'allowed link domains',
        'store_link' => 'store link',
        'outside_hours_message' => 'outside-hours message',

        // test links
        'label' => 'name',
        'max_messages_per_session' => 'max messages per session',
        'max_sessions' => 'max sessions',

        // filters
        'from' => 'start date',
        'to' => 'end date',
        'q' => 'search',
        'file' => 'file',
    ],

];
