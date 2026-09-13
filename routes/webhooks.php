<?php

// Inbound webhook routes (no CSRF, no session). Populated in later tasks.

use App\Channels\Http\WebhookController;
use App\Commerce\Http\ShopifyWebhookController;
use Illuminate\Support\Facades\Route;

Route::match(['get', 'post'], '/webhooks/{platform}', [WebhookController::class, 'handle'])
    ->whereIn('platform', ['facebook', 'instagram', 'whatsapp', 'tiktok']);

Route::post('/webhooks/shopify/{topic}', [ShopifyWebhookController::class, 'handle']);
