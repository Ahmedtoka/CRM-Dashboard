<?php

use App\Http\Controllers\Web\FallbackController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\StartSessionIfCookie;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

/*
 * The catch-all for unknown addresses (loaded last, in bootstrap/app.php). It renders the app-level
 * Error page (bootstrap/app.php → respond) with the locale and the shared props, inside the app
 * when she is signed in. Its own stack instead of the `web` group:
 * - the session starts only when the request already has the session cookie (StartSessionIfCookie,
 *   placed BEFORE SetLocale / HandleInertiaRequests so they see the signed-in user); a scanner, bot
 *   or webhook miss writes no `sessions` row and gets no Set-Cookie;
 * - no CSRF check, which would turn an unknown POST's 404 into a 419;
 * - every verb, so a POST to an unknown address stays a 404 (a GET-only fallback answers 405);
 *   a known address with the wrong verb still answers 405 (FallbackController).
 */
Route::middleware([
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSessionIfCookie::class,
    SubstituteBindings::class,
    SetLocale::class,
    HandleInertiaRequests::class,
    AddLinkHeadersForPreloadedAssets::class,
])->group(function () {
    Route::any('{fallbackPlaceholder}', FallbackController::class)
        ->where('fallbackPlaceholder', '.*')
        ->fallback();
});
