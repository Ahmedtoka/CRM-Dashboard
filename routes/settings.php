<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Web\Settings\NotificationSettingsController;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Self-service account deletion is intentionally not offered: accounts are deactivated
// by an admin so that attribution history (participants, orders, logs) stays intact.
Route::middleware(['auth', EnsureUserIsActive::class])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance');

    Route::get('settings/notifications', [NotificationSettingsController::class, 'edit'])->name('settings.notifications.edit');
    Route::patch('settings/notifications', [NotificationSettingsController::class, 'update'])->name('settings.notifications.update');
});
