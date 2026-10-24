<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CaseController;
use App\Http\Controllers\Api\V1\CityController;
use App\Http\Controllers\Api\V1\CommentController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\FinishOptionsController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PresenceController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\QueueDeskController;
use App\Http\Controllers\Api\V1\QuickReplyController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\ShippingController;
use App\Http\Controllers\Api\V1\TagController;
use App\Http\Controllers\Api\V1\WhatsappTemplateController;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RejectAdsRolesOnApi;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

// Mobile API (spec §8). Everything except login requires a Sanctum token.
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:mobile-login')->name('auth.login');

    Route::middleware(['auth:sanctum', EnsureUserIsActive::class, RejectAdsRolesOnApi::class, SetLocale::class])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('heartbeat', [PresenceController::class, 'heartbeat'])->name('heartbeat');

        // Moderator mobile app (docs/mobile-app/API-CONTRACT.md in the outer repo).
        Route::post('auth/refresh', [AuthController::class, 'refresh'])->middleware('throttle:mobile-refresh')->name('auth.refresh');
        Route::post('devices', [DeviceController::class, 'store'])->name('devices.store');
        Route::delete('devices', [DeviceController::class, 'destroy'])->name('devices.destroy');
        Route::post('devices/test', [DeviceController::class, 'test'])->middleware('throttle:mobile-devices-test')->name('devices.test');

        Route::middleware('throttle:mobile-queue')->group(function () {
            Route::get('queue/me', [QueueDeskController::class, 'me'])->name('queue.me');
            Route::get('queue/me/points', [QueueDeskController::class, 'points'])->name('queue.me.points');
            Route::get('queue/me/auto-closed', [ConversationController::class, 'autoClosed'])->name('queue.me.auto-closed');
            Route::post('queue/me/check-in', [QueueDeskController::class, 'checkIn'])->name('queue.check-in');
            Route::post('queue/me/check-out', [QueueDeskController::class, 'checkOut'])->name('queue.check-out');
            Route::post('queue/me/hand-back', [QueueDeskController::class, 'handBack'])->name('queue.hand-back');
            Route::post('queue/me/status', [QueueDeskController::class, 'status'])->name('queue.status');
            Route::post('queue/entries/{entry}/close', [QueueDeskController::class, 'close'])->name('queue.entries.close');
            Route::post('queue/entries/{entry}/escalate', [QueueDeskController::class, 'escalate'])->name('queue.entries.escalate');
        });

        Route::get('cases', [CaseController::class, 'index'])->name('cases.index');
        Route::get('cases/{supportCase}', [CaseController::class, 'show'])->name('cases.show');
        Route::patch('cases/{supportCase}', [CaseController::class, 'update'])->name('cases.update');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read', [NotificationController::class, 'read'])->name('notifications.read');

        Route::get('tags', [TagController::class, 'index'])->name('tags.index');

        // Declared before conversations/{conversation} so "counts" is not taken for an id.
        Route::get('conversations/counts', [ConversationController::class, 'counts'])->name('conversations.counts');
        Route::get('conversations/{conversation}/context', [ConversationController::class, 'context'])->name('conversations.context');
        Route::post('conversations/{conversation}/tags', [ConversationController::class, 'syncTags'])->name('conversations.tags');
        Route::post('conversations/{conversation}/quick-replies/{quickReply}/render', [ConversationController::class, 'renderQuickReply'])->name('conversations.quick-replies.render');
        Route::post('conversations/{conversation}/product', [ConversationController::class, 'sendProduct'])->name('conversations.product.store');

        // Mobile r2 (docs/round-2026-10-24/finish-api.md): the «خلصت» sheet's outcome list, labels and groups.
        Route::get('finish/options', FinishOptionsController::class)->name('finish.options');

        Route::get('conversations', [ConversationController::class, 'list'])->middleware('record-list-latency')->name('conversations.index');
        Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
        Route::get('conversations/{conversation}/messages', [ConversationController::class, 'messages'])->name('conversations.messages.index');
        Route::post('conversations/{conversation}/messages', [ConversationController::class, 'sendMessage'])->name('conversations.messages.store');
        Route::post('conversations/{conversation}/attachments', [ConversationController::class, 'uploadAttachment'])->name('conversations.attachments.store');
        Route::post('conversations/{conversation}/notes', [ConversationController::class, 'storeNote'])->name('conversations.notes.store');
        Route::post('conversations/{conversation}/claim', [ConversationController::class, 'claim'])->name('conversations.claim');
        Route::get('conversations/{conversation}/mentionable', [ConversationController::class, 'mentionable'])->name('conversations.mentionable');
        Route::post('conversations/{conversation}/finish', [ConversationController::class, 'finish'])->name('conversations.finish');
        Route::post('conversations/{conversation}/finish/undo', [ConversationController::class, 'undoFinish'])->name('conversations.finish.undo');
        Route::post('conversations/{conversation}/outcome', [ConversationController::class, 'amendOutcome'])->name('conversations.outcome');
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

        // Global search (Dashboard Experience Task 13, spec §5.3): additive-only, mobile command palette data source.
        Route::get('search', SearchController::class)->middleware('throttle:mobile-search')->name('search');

        Route::get('shipping/provinces', [ShippingController::class, 'provinces'])->name('shipping.provinces');
        Route::get('shipping/quote', [ShippingController::class, 'quote'])->name('shipping.quote');

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
