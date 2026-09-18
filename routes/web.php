<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\Web\LegalController;
use App\Http\Controllers\Web\LocaleController;
use App\Http\Controllers\Web\MediaController;
use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// There is no landing page or dashboard: the inbox is the home screen.
Route::get('/', fn (Request $request) => redirect()->route($request->user() ? 'inbox' : 'login'))->name('home');

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

Route::get('dashboard', fn () => redirect()->route('inbox'))->middleware('auth')->name('dashboard');

// Language switch on the login / password pages (signed-in users use POST /locale/{locale}).
Route::post('/guest/locale/{locale}', [LocaleController::class, 'guest'])
    ->middleware('guest')
    ->whereIn('locale', SetLocale::SUPPORTED)
    ->name('locale.guest');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
