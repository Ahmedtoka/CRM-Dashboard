<?php

use App\Http\Controllers\Web\Ads\AccountController;
use App\Http\Controllers\Web\Ads\BuyerController;
use App\Http\Controllers\Web\Ads\BuyerSetupController;
use App\Http\Controllers\Web\Ads\CreativeController;
use App\Http\Controllers\Web\Ads\OverviewController;
use Illuminate\Support\Facades\Route;

// Ads Hub. Required from routes/crm.php inside the authenticated group (see EnsureAdsAccess for the `ads:*` areas).
Route::middleware('ads:report')->group(function () {
    Route::get('/ads', OverviewController::class)->name('ads.overview');
    Route::get('/ads/buyers', [BuyerController::class, 'index'])->name('ads.buyers.index');
    Route::get('/ads/buyers/{buyer}', [BuyerController::class, 'show'])->name('ads.buyers.show');
    Route::get('/ads/creatives', [CreativeController::class, 'index'])->name('ads.creatives.index');
    Route::get('/ads/creatives/{ad}', [CreativeController::class, 'show'])->name('ads.creatives.show');
    Route::get('/ads/winners', [CreativeController::class, 'winners'])->name('ads.winners');
});

Route::middleware('ads:manage')->group(function () {
    Route::get('/ads/accounts', [AccountController::class, 'index'])->name('ads.accounts.index');
    Route::post('/ads/connections', [AccountController::class, 'store'])->name('ads.connections.store');
    Route::put('/ads/connections/{connection}', [AccountController::class, 'update'])->name('ads.connections.update');
    Route::post('/ads/connections/{connection}/test', [AccountController::class, 'test'])->name('ads.connections.test');
    Route::post('/ads/connections/{connection}/sync', [AccountController::class, 'sync'])->name('ads.connections.sync');
    Route::delete('/ads/connections/{connection}', [AccountController::class, 'destroy'])->name('ads.connections.destroy');
    Route::post('/ads/accounts/{account}/assign', [AccountController::class, 'assign'])->name('ads.accounts.assign');
    Route::patch('/ads/accounts/{account}', [AccountController::class, 'updateAccount'])->name('ads.accounts.update');
    Route::post('/ads/accounts/{account}/sync', [AccountController::class, 'syncAccount'])->name('ads.accounts.sync');

    Route::get('/ads/setup/buyers', [BuyerSetupController::class, 'index'])->name('ads.setup.buyers');
    Route::post('/ads/setup/buyers', [BuyerSetupController::class, 'store'])->name('ads.setup.buyers.store');
    Route::put('/ads/setup/buyers/{buyer}', [BuyerSetupController::class, 'update'])->name('ads.setup.buyers.update');
    Route::delete('/ads/setup/buyers/{buyer}', [BuyerSetupController::class, 'destroy'])->name('ads.setup.buyers.destroy');
    Route::put('/ads/setup/buyers/{buyer}/targets', [BuyerSetupController::class, 'targets'])->name('ads.setup.buyers.targets');
    Route::put('/ads/setup/settings', [BuyerSetupController::class, 'settings'])->name('ads.setup.settings');
});

// Task 12 replaces this placeholder with the materials library.
Route::get('/ads/materials', fn () => response('ok'))->middleware('ads:materials')->name('ads.materials.index');
