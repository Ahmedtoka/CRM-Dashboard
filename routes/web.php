<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\Web\FallbackController;
use App\Http\Controllers\Web\LegalController;
use App\Http\Controllers\Web\LocaleController;
use App\Http\Controllers\Web\MediaController;
use App\Http\Controllers\Web\TryController;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\StartSessionIfCookie;
use App\Onboarding\HomeRoute;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// There is no landing page or dashboard: the inbox is the home screen.
Route::get('/', fn (Request $request) => $request->user() ? redirect(HomeRoute::for($request->user())) : redirect()->route('login'))->name('home');

// Staging health check (live-test phase-1 task 4). Outside auth; token-gated in the
// controller itself (wrong/missing X-Health-Token -> 404, not 401 — see HealthController).
Route::get('/up/crm', HealthController::class)->name('health.crm');

// Short-lived signed public media url (spec §1, "Security": expires after 60 min).
// Lives in this file (so it does get the default `web` group), but the signature —
// not a session — is what authorises it: for a caller that can't carry a browser
// session, such as a channel provider fetching an attachment back for an outbound
// send (Task 2). `MediaController::publicShow()` additionally refuses any attachment
// that isn't yet linked to a message.
Route::get('/media/public/{attachment}', [MediaController::class, 'publicShow'])->middleware('signed')->name('media.public');

// Public legal pages (Meta App Review): open to guests and signed-in users alike.
Route::get('/privacy', [LegalController::class, 'privacy'])->name('legal.privacy');
Route::get('/terms', [LegalController::class, 'terms'])->name('legal.terms');
Route::get('/data-deletion', [LegalController::class, 'dataDeletion'])->name('legal.data-deletion');

// Public team test links (design 2026-09-21): no login, the token is the only secret.
// They keep the `web` group for the browser session that owns a tester's run and for
// CSRF on the writes; both views carry `noindex, nofollow, noarchive`, and the
// controller rate-limits per run and per address.
Route::prefix('try/{token}')->name('try.')->where(['token' => '[a-z0-9]{8,64}'])->group(function () {
    Route::get('/', [TryController::class, 'show'])->name('show');
    Route::get('state', [TryController::class, 'poll'])->name('state');
    Route::post('start', [TryController::class, 'start'])->name('start');
    Route::post('messages', [TryController::class, 'send'])->name('send');
    Route::post('photo', [TryController::class, 'photo'])->name('photo');
    Route::post('reset', [TryController::class, 'reset'])->name('reset');
    Route::get('media/{attachment}', [TryController::class, 'media'])->name('media');
});

Route::get('dashboard', fn () => redirect()->route('inbox'))->middleware('auth')->name('dashboard');

// Language switch on the login / password pages (signed-in users use POST /locale/{locale}).
Route::post('/guest/locale/{locale}', [LocaleController::class, 'guest'])
    ->middleware('guest')
    ->whereIn('locale', SetLocale::SUPPORTED)
    ->name('locale.guest');

// Unknown addresses run through the `web` group (locale, shared props), so the 404 page knows
// who is signed in and renders inside the app (bootstrap/app.php → respond).
// - Every verb, so a POST to an unknown address stays a 404 (a GET-only fallback answers 405);
//   a known address with the wrong verb still answers 405 (FallbackController).
// - No CSRF check, which would turn that 404 into a 419.
// - The session starts only when the request already has the session cookie: a scanner, bot or
//   webhook miss writes no `sessions` row and gets no Set-Cookie (stateless guest error page).
Route::any('{fallbackPlaceholder}', FallbackController::class)
    ->where('fallbackPlaceholder', '.*')
    ->fallback()
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class])
    ->middleware(StartSessionIfCookie::class);

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
