<?php

// The Shopify app's embedded home (round 2026-10-17): stateless like the webhooks (no session,
// cookies, CSRF or Inertia). Every request carries a Shopify ID token instead.

use App\Http\Controllers\Shopify\EmbeddedAppController;
use App\Http\Middleware\ShopifyFrameAncestors;
use Illuminate\Support\Facades\Route;

Route::middleware([ShopifyFrameAncestors::class, 'throttle:60,1'])->group(function () {
    Route::get('/shopify/app', [EmbeddedAppController::class, 'show'])->name('shopify.app');
    Route::post('/shopify/app/session', [EmbeddedAppController::class, 'session'])->name('shopify.app.session');
});
