<?php

// Inbound webhook routes (no CSRF, no session). Populated in later tasks.

use App\Channels\Http\WebhookController;
use App\Commerce\Http\ShopifyWebhookController;
use App\Legal\Http\DataDeletionCallbackController;
use Illuminate\Support\Facades\Route;

Route::match(['get', 'post'], '/webhooks/{platform}', [WebhookController::class, 'handle'])
    ->whereIn('platform', ['facebook', 'instagram', 'whatsapp', 'tiktok']);

Route::post('/webhooks/shopify/{topic}', [ShopifyWebhookController::class, 'handle']);

// Meta Data Deletion Callback (app dashboard: Settings > Basic > Data deletion). Signed
// with the app secret; answers with the /data-deletion status URL and confirmation code.
Route::post('/webhooks/facebook/data-deletion', DataDeletionCallbackController::class)->name('webhooks.facebook.data-deletion');
