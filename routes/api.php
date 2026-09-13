<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CityController;
use App\Http\Controllers\Api\V1\CommentController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PresenceController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\QuickReplyController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\WhatsappTemplateController;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

// Mobile API (spec §8). Everything except login requires a Sanctum token.
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('auth.login');

    Route::middleware(['auth:sanctum', EnsureUserIsActive::class, SetLocale::class])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('heartbeat', [PresenceController::class, 'heartbeat'])->name('heartbeat');

        Route::get('conversations', [ConversationController::class, 'list'])->middleware('record-list-latency')->name('conversations.index');
        Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
        Route::get('conversations/{conversation}/messages', [ConversationController::class, 'messages'])->name('conversations.messages.index');
        Route::post('conversations/{conversation}/messages', [ConversationController::class, 'sendMessage'])->name('conversations.messages.store');
        Route::post('conversations/{conversation}/notes', [ConversationController::class, 'storeNote'])->name('conversations.notes.store');
        Route::post('conversations/{conversation}/resolve', [ConversationController::class, 'resolve'])->name('conversations.resolve');
        Route::post('conversations/{conversation}/reopen', [ConversationController::class, 'reopen'])->name('conversations.reopen');
        Route::post('conversations/{conversation}/return-to-bot', [ConversationController::class, 'returnToBot'])->name('conversations.return-to-bot');
        Route::post('conversations/{conversation}/typing', [ConversationController::class, 'typing'])->name('conversations.typing');
        Route::post('conversations/{conversation}/read', [ConversationController::class, 'read'])->name('conversations.read');
        Route::post('conversations/{conversation}/priority', [ConversationController::class, 'setPriority'])->name('conversations.priority');
        Route::post('conversations/{conversation}/orders', [ConversationController::class, 'storeOrder'])->name('conversations.orders.store');
        Route::post('messages/{message}/retry', [ConversationController::class, 'retryMessage'])->name('messages.retry');

        Route::get('quick-replies', [QuickReplyController::class, 'index'])->name('quick-replies.index');
        Route::get('whatsapp-templates', WhatsappTemplateController::class)->name('whatsapp-templates.index');
        Route::get('cities', CityController::class)->name('cities.index');

        Route::get('comments', [CommentController::class, 'feed'])->name('comments.index');
        Route::post('comments/{comment}/reply', [CommentController::class, 'reply'])->name('comments.reply');
        Route::post('comments/{comment}/hide', [CommentController::class, 'hide'])->name('comments.hide');
        Route::post('comments/{comment}/private-reply', [CommentController::class, 'privateReply'])->name('comments.private-reply');

        Route::get('products', [ProductController::class, 'search'])->name('products.index');

        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('orders/{order}/retry', [OrderController::class, 'retry'])->name('orders.retry');

        Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');

        Route::get('reports/me', [ReportController::class, 'me'])->name('reports.me');
        Route::middleware('role:supervisor')->group(function () {
            Route::get('reports/team', [ReportController::class, 'team'])->name('reports.team');
            Route::get('reports/users/{user}', [ReportController::class, 'user'])->name('reports.users.show');
            Route::get('reports/bot', [ReportController::class, 'bot'])->name('reports.bot');
        });
    });
});

// POST /api/v1/broadcasting/auth for the Flutter Reverb client (kept outside the named group).
Route::prefix('v1')->group(fn () => Broadcast::routes(['middleware' => ['auth:sanctum', EnsureUserIsActive::class]]));
