<?php

use Illuminate\Support\Facades\Route;

// Ads Hub. Required from routes/crm.php inside the authenticated group; the real controllers
// replace these placeholders in later tasks.
Route::get('/ads', fn () => response('ok'))->name('ads.overview');
Route::get('/ads/materials', fn () => response('ok'))->name('ads.materials.index');
