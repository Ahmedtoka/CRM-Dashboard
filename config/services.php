<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // TikTok Business Messaging (x6, 2026-10-24): the owner's developer app («Ads Reporting Tool») with the
    // Business Messaging permission. The app secret signs every webhook (Tiktok-Signature) and exchanges the
    // OAuth code; the authorize URL is the portal's own «TikTok account holder authorization URL» when pasted
    // (TIKTOK_AUTHORIZE_URL), else built from authorize_base with our scopes.
    'tiktok' => [
        'app_id' => env('TIKTOK_APP_ID'),
        'app_secret' => env('TIKTOK_APP_SECRET'),
        // 'live' calls business-api.tiktok.com; 'fake' records every call in FakeTikTokApi (tests, demo).
        'driver' => env('TIKTOK_DM_DRIVER', env('APP_ENV') === 'production' ? 'live' : 'fake'),
        'base_url' => env('TIKTOK_BUSINESS_API_URL', 'https://business-api.tiktok.com/open_api/v1.3'),
        'authorize_url' => env('TIKTOK_AUTHORIZE_URL'),
        'authorize_base' => env('TIKTOK_AUTHORIZE_BASE', 'https://www.tiktok.com/v2/auth/authorize'),
        'scopes' => ['user.info.basic', 'user.account.type', 'message.list.read', 'message.list.send', 'message.list.manage'],
        // A webhook whose signature timestamp is older than this is refused (replay); 0 disables the age check.
        'signature_tolerance_seconds' => (int) env('TIKTOK_WEBHOOK_TOLERANCE', 300),
        // Bot buttons: 'card' = up to 3 as a Q&A button card after the text, 'text' = always a numbered list.
        'menu_style' => env('TIKTOK_MENU_STYLE', 'card'),
        'menu_prompt' => env('TIKTOK_MENU_PROMPT', 'اختاري من هنا'),
    ],

];
