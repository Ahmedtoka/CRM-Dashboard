<?php

return [
    'drivers' => [
        'channels' => env('CRM_CHANNEL_DRIVER', 'fake'),
        'commerce' => env('CRM_COMMERCE_DRIVER', 'fake'),
        'shipping' => env('CRM_SHIPPING_DRIVER', 'fake'),
        'ai' => env('CRM_AI_DRIVER', 'fake'),
        // Order-management system for the bot's order status lookup: 'live' (needs CRM_OMS_BASE_URL) or 'fake'.
        'oms' => env('CRM_OMS_DRIVER', 'fake'),
    ],

    // OMS HTTP client (App\Bot\Flow\Orders\HttpOmsClient) — a placeholder contract until the owner shares the API.
    'oms' => [
        'base_url' => env('CRM_OMS_BASE_URL'),
        'token' => env('CRM_OMS_TOKEN'),
        'timeout' => 8,
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

    // Public legal pages (/privacy, /terms, /data-deletion) required by Meta App Review.
    // contact_email is shown as the privacy / deletion contact; when empty the pages point
    // people to the Facebook Page instead. retention_months is quoted in the privacy policy.
    'legal' => [
        'company_name' => env('LEGAL_COMPANY_NAME', 'Le Voile'),
        'contact_email' => env('LEGAL_CONTACT_EMAIL'),
        'retention_months' => (int) env('LEGAL_RETENTION_MONTHS', 24),
    ],

    // Behind a TLS-terminating proxy (Cloudflare tunnel locally, Nginx on staging)
    // trust its X-Forwarded-* headers so generated URLs keep https. Comma-separated
    // IPs, or '*'; empty trusts nothing. Applied in AppServiceProvider::boot().
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    'meta' => [
        'app_secret' => env('META_APP_SECRET'),
        'verify_token' => env('META_VERIFY_TOKEN', 'crm-verify'),
        'graph_version' => env('META_GRAPH_VERSION', 'v23.0'),

        // "Connect with Facebook" (Facebook Login) on Settings → Channels. The app id is
        // public; the token exchange also needs app_secret above. login_config_id is the
        // optional Facebook Login for Business configuration id — when set it replaces
        // the explicit scope list in the login dialog.
        'app_id' => env('META_APP_ID'),
        'login_config_id' => env('META_LOGIN_CONFIG_ID'),
    ],

    // Perf instrumentation (spec §11.3). Off by default so ordinary requests never
    // pay the recording cost; enable on staging for the load test / latency report.
    'latency' => [
        'enabled' => (bool) env('CRM_LATENCY_ENABLED', false),
        'sample_rate' => (float) env('CRM_LATENCY_SAMPLE_RATE', 1.0),
        'workers_note' => env('CRM_LATENCY_WORKERS_NOTE', 'Supervisor: reverb, default, webhooks, outbound, bot, media queues'),

        // Acceptance targets (spec §11.3, plan Global Constraints — exact p95 values in ms),
        // shared by the /reports/latency page and the latency report command
        // (App\Analytics\Commands\LatencyReportCommand::DEFAULT_TARGETS is only its fallback).
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

        // RunBulkImportStage runs on the `commerce` queue of the `redis`
        // connection: retry_after 90 s, job $timeout 80 s, worker --timeout=80.
        // Each step stops after a 45 s wall-clock budget (import_job_seconds)
        // and continues in a re-dispatched job, leaving ~35 s headroom for the
        // chunk in flight: rows per chunk, chunks per job, seconds per job.
        'import_chunk_size' => 500,
        'import_chunks_per_job' => 20,
        'import_job_seconds' => 45,

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

        // Required scopes that don't block connecting when missing (shown as a
        // warning). The write scopes only power creating orders, customers and
        // payment-link orders from the CRM, so a store can be connected read-only
        // (e.g. to test a real store without the CRM ever changing it).
        'optional_scopes' => [
            'write_customers',
            'write_orders',
            'write_draft_orders',
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

    // Egyptian governorates (ISO 3166-2:EG) -> Arabic name, for the order drawer's
    // province select and GET /shipping/provinces (spec §5.1, plan Task 9). Regions
    // synced from Shopify whose code isn't in this map fall back to their Shopify name.
    'eg_provinces' => [
        'ALX' => 'الإسكندرية',
        'ASN' => 'أسوان',
        'AST' => 'أسيوط',
        'BA' => 'البحر الأحمر',
        'BH' => 'البحيرة',
        'BNS' => 'بني سويف',
        'C' => 'القاهرة',
        'DK' => 'الدقهلية',
        'DT' => 'دمياط',
        'FYM' => 'الفيوم',
        'GH' => 'الغربية',
        'GZ' => 'الجيزة',
        'IS' => 'الإسماعيلية',
        'JS' => 'جنوب سيناء',
        'KB' => 'القليوبية',
        'KFS' => 'كفر الشيخ',
        'KN' => 'قنا',
        'LX' => 'الأقصر',
        'MN' => 'المنيا',
        'MNF' => 'المنوفية',
        'MT' => 'مطروح',
        'PTS' => 'بورسعيد',
        'SHG' => 'سوهاج',
        'SHR' => 'الشرقية',
        'SIN' => 'شمال سيناء',
        'SUZ' => 'السويس',
        'WAD' => 'الوادي الجديد',
    ],

    // Shipment is created automatically when an order becomes confirmed/paid, or
    // (when false) left to a supervisor to trigger manually (spec §5.8.4).
    'auto_create_shipment' => true,

    'notify_customer_on_shipment' => env('CRM_NOTIFY_CUSTOMER_ON_SHIPMENT', false),

    'bot' => [
        // Defaults for new bot_settings rows; phpunit.xml pins them to 0 so synchronous tests stay synchronous.
        'burst_wait_seconds' => (int) env('CRM_BOT_BURST_WAIT_SECONDS', 8),
        'burst_max_wait_seconds' => (int) env('CRM_BOT_MAX_WAIT_SECONDS', 25),
        'typing_ms_per_char' => (int) env('CRM_BOT_TYPING_MS_PER_CHAR', 35),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'classifier_model' => 'claude-haiku-4-5-20251001',
        'reply_model' => 'claude-sonnet-5',
        'timeout' => 10,
        // Message bot per-call budgets (spec: AI reply < 8 s end to end).
        'classify_timeout' => 3,
        'reply_timeout' => 5,
        // Conversation-turn flow: one understanding call over history + the whole
        // burst, and one compose call merging approved scripts (spec §2.1).
        'understand_timeout' => 8,
        'compose_timeout' => 10,
        // Nightly learning (design §6): one big call over a whole day of transcripts.
        'learning_timeout' => 60,
        // Learning v2 §2: one small call per finished conversation.
        'review_timeout' => 30,
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

    // Global search (spec §5.3, final fix wave I3): message-text search windows. The
    // Arabic LIKE path can't use an index on body, so it scans a shorter window.
    'search' => [
        'window_days' => 180,
        'arabic_window_days' => 90,
    ],

    'soft_lock_seconds' => 45,

    // Explicit "استلام" claim (spec §5.4, Dashboard Experience Task 15): a forced
    // soft lock that takes over from any current holder, and the recency window
    // for the handling indicator's "last human reply" fallback.
    'claim_lock_minutes' => 30,
    'handling_recent_minutes' => 30,

    'comment_bot_delay' => [5, 30],

    'private_reply_days' => 7,

    // Learning v2 (spec 2026-09-18 §1-2): the bot learns from real conversations
    // only — a conversation counts when its channel account's driver is listed
    // here (the demo accounts use `fake`). Per-conversation reviews stop at the
    // daily cap; beyond it they are skipped with a log line.
    'learning' => [
        'drivers' => ['live'],
        'max_reviews_per_day' => 200,
        'review_delay_minutes' => 10,
        'backfill' => 30,
    ],

    'timezone_display' => 'Africa/Cairo',

    'currency' => 'EGP',

    // Media attachments (spec §1, Dashboard Experience Task 1): storage, size limits
    // per attachment type, and per-platform limits enforced again before send.
    'media' => [
        'disk' => env('CRM_MEDIA_DISK', 'media'),
        'signed_url_minutes' => 60,
        'orphan_hours' => 24,
        'max_per_message' => 10,
        'ffmpeg_path' => env('CRM_FFMPEG_PATH'),
        'types' => [
            'image' => ['mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], 'max_bytes' => 8 * 1024 * 1024],
            'video' => ['mimes' => ['video/mp4', 'video/3gpp', 'video/quicktime'], 'max_bytes' => 25 * 1024 * 1024],
            'audio' => ['mimes' => ['audio/ogg', 'audio/opus', 'audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/webm'], 'max_bytes' => 16 * 1024 * 1024],
            'file' => ['mimes' => ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv', 'text/plain', 'application/zip'],
                'max_bytes' => 25 * 1024 * 1024],
        ],
        // null mimes = any mime allowed for the type globally; missing type = not sendable on that platform.
        'platforms' => [
            'whatsapp' => [
                'image' => ['mimes' => ['image/jpeg', 'image/png'], 'max_bytes' => 5 * 1024 * 1024],
                'video' => ['mimes' => ['video/mp4'], 'max_bytes' => 16 * 1024 * 1024],
                'audio' => ['mimes' => ['audio/ogg', 'audio/opus', 'audio/mpeg', 'audio/aac', 'audio/mp4', 'audio/x-m4a', 'audio/webm'], 'max_bytes' => 16 * 1024 * 1024],
                'file' => ['mimes' => null, 'max_bytes' => 25 * 1024 * 1024],
                'sticker' => ['mimes' => ['image/webp'], 'max_bytes' => 1024 * 1024],
            ],
            'facebook' => [
                'image' => ['mimes' => null, 'max_bytes' => 8 * 1024 * 1024], 'video' => ['mimes' => null, 'max_bytes' => 25 * 1024 * 1024],
                'audio' => ['mimes' => null, 'max_bytes' => 25 * 1024 * 1024], 'file' => ['mimes' => null, 'max_bytes' => 25 * 1024 * 1024],
                'sticker' => ['mimes' => null, 'max_bytes' => 8 * 1024 * 1024],
            ],
            'instagram' => [
                'image' => ['mimes' => null, 'max_bytes' => 8 * 1024 * 1024], 'video' => ['mimes' => null, 'max_bytes' => 25 * 1024 * 1024],
                'audio' => ['mimes' => null, 'max_bytes' => 25 * 1024 * 1024], 'sticker' => ['mimes' => null, 'max_bytes' => 8 * 1024 * 1024],
            ],
            'tiktok' => [
                'image' => ['mimes' => null, 'max_bytes' => 25 * 1024 * 1024], 'video' => ['mimes' => null, 'max_bytes' => 25 * 1024 * 1024],
                'audio' => ['mimes' => null, 'max_bytes' => 25 * 1024 * 1024], 'file' => ['mimes' => null, 'max_bytes' => 25 * 1024 * 1024],
                'sticker' => ['mimes' => null, 'max_bytes' => 25 * 1024 * 1024],
            ],
        ],
    ],

    // One-click login panel on the login page, for the owner's own machine only.
    // Guarded twice: this switch AND app()->environment('local') — a server with
    // APP_ENV=production never renders the panel and 404s the route.
    'dev_quick_login' => (bool) env('CRM_DEV_QUICK_LOGIN', false),

    // Developer-only pages (the /simulator and the /reports/latency report). Off by
    // default: both disappear from the nav and their routes 404 (EnsureDevToolsEnabled).
    'dev_tools' => (bool) env('CRM_DEV_TOOLS', false),

    // Approved WhatsApp templates (spec §5.6), shared with the web
    // TemplatePicker (resources/js/components/crm/TemplatePicker.vue) and the
    // mobile app so both stop hardcoding the name/param-count list.
    'whatsapp_templates' => [
        ['name' => 'order_update', 'language' => 'ar', 'params' => 2, 'label_ar' => 'تحديث الطلب', 'label_en' => 'Order update'],
        ['name' => 'follow_up', 'language' => 'ar', 'params' => 1, 'label_ar' => 'متابعة', 'label_en' => 'Follow up'],
        ['name' => 'back_in_stock', 'language' => 'ar', 'params' => 1, 'label_ar' => 'عاد للمخزون', 'label_en' => 'Back in stock'],
    ],
];
