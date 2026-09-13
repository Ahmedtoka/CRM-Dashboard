<?php

return [
    'drivers' => [
        'channels' => env('CRM_CHANNEL_DRIVER', 'fake'),
        'commerce' => env('CRM_COMMERCE_DRIVER', 'fake'),
        'shipping' => env('CRM_SHIPPING_DRIVER', 'fake'),
        'ai' => env('CRM_AI_DRIVER', 'fake'),
    ],

    // Fake-driver webhooks (and TikTok, which is always fake for now) carry no
    // signature. They are accepted automatically only in local/testing; set this
    // to true to accept them on another environment (e.g. a public demo server).
    'allow_fake_webhooks' => (bool) env('CRM_ALLOW_FAKE_WEBHOOKS', false),

    // GET /up/crm staging health check. When empty the endpoint always 404s
    // (not discoverable) — set a long random value in the staging .env.
    'health' => [
        'token' => env('CRM_HEALTH_TOKEN'),
    ],

    'meta' => [
        'app_secret' => env('META_APP_SECRET'),
        'verify_token' => env('META_VERIFY_TOKEN', 'crm-verify'),
        'graph_version' => env('META_GRAPH_VERSION', 'v23.0'),
    ],

    // Perf instrumentation (spec §11.3). Off by default so ordinary requests never
    // pay the recording cost; enable on staging for the load test / latency report.
    'latency' => [
        'enabled' => (bool) env('CRM_LATENCY_ENABLED', false),
        'sample_rate' => (float) env('CRM_LATENCY_SAMPLE_RATE', 1.0),
        'workers_note' => env('CRM_LATENCY_WORKERS_NOTE', 'Supervisor: reverb, default, webhooks, outbound, bot queues'),

        // Acceptance targets (spec §11.3, plan Global Constraints — exact p95 values in ms),
        // shared by the /reports/latency page. See also App\Analytics\Commands\LatencyReportCommand::TARGETS.
        'targets' => [
            'inbound' => 2000,
            'outbound' => 1500,
            'list' => 300,
        ],
    ],

    'shopify' => [
        'shop' => env('SHOPIFY_SHOP'),
        'token' => env('SHOPIFY_ADMIN_TOKEN'),
        'api_version' => env('SHOPIFY_API_VERSION', '2025-07'),
        'webhook_secret' => env('SHOPIFY_WEBHOOK_SECRET'),

        // 'fake' (default) needs no real store — every screen and test works
        // against FakeShopifyTransport. Set to 'live' to call Shopify for real.
        'driver' => env('CRM_SHOPIFY_DRIVER', 'fake'),

        // Initial import pulls orders created in the last N months (spec §4.1).
        'import_orders_months' => (int) env('CRM_SHOPIFY_IMPORT_ORDERS_MONTHS', 12),

        // Exact scopes requested on install (plan Global Constraints).
        'required_scopes' => [
            'read_products',
            'read_inventory',
            'read_customers',
            'write_customers',
            'read_orders',
            'write_orders',
            'read_draft_orders',
            'write_draft_orders',
            'read_fulfillments',
            'read_shipping',
        ],

        // Exact webhook topics registered on connect (plan Global Constraints,
        // spec §4.2). Callback URL is APP_URL/webhooks/shopify/{topic with
        // "/" replaced by "-"}.
        'webhook_topics' => [
            'products/create',
            'products/update',
            'products/delete',
            'inventory_levels/update',
            'customers/create',
            'customers/update',
            'orders/create',
            'orders/updated',
            'orders/paid',
            'orders/cancelled',
            'fulfillments/create',
            'fulfillments/update',
            'refunds/create',
            'draft_orders/update',
            'app/uninstalled',
        ],
    ],

    // Shipment is created automatically when an order becomes confirmed/paid, or
    // (when false) left to a supervisor to trigger manually (spec §5.8.4).
    'auto_create_shipment' => true,

    'notify_customer_on_shipment' => env('CRM_NOTIFY_CUSTOMER_ON_SHIPMENT', false),

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'classifier_model' => 'claude-haiku-4-5-20251001',
        'reply_model' => 'claude-sonnet-5',
        'timeout' => 10,
        // Per-million-token USD prices, keyed by model, used to compute
        // BotRun.cost_usd from the classifier/reply token counts.
        'prices' => [
            'claude-haiku-4-5-20251001' => ['input' => 1, 'output' => 5],
            'claude-sonnet-5' => ['input' => 3, 'output' => 15],
        ],
    ],

    'attribution' => [
        'follow_up_hours' => 12,
    ],

    'soft_lock_seconds' => 45,

    'comment_bot_delay' => [5, 30],

    'private_reply_days' => 7,

    'timezone_display' => 'Africa/Cairo',

    'currency' => 'EGP',

    // Approved WhatsApp templates (spec §5.6), shared with the web
    // TemplatePicker (resources/js/components/crm/TemplatePicker.vue) and the
    // mobile app so both stop hardcoding the name/param-count list.
    'whatsapp_templates' => [
        ['name' => 'order_update', 'language' => 'ar', 'params' => 2, 'label_ar' => 'تحديث الطلب', 'label_en' => 'Order update'],
        ['name' => 'follow_up', 'language' => 'ar', 'params' => 1, 'label_ar' => 'متابعة', 'label_en' => 'Follow up'],
        ['name' => 'back_in_stock', 'language' => 'ar', 'params' => 1, 'label_ar' => 'عاد للمخزون', 'label_en' => 'Back in stock'],
    ],
];
