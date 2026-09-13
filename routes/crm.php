<?php

// CRM application routes (loaded with web + auth middleware in bootstrap/app.php).

use App\Http\Controllers\Web\CommentController;
use App\Http\Controllers\Web\CustomerController;
use App\Http\Controllers\Web\InboxController;
use App\Http\Controllers\Web\LocaleController;
use App\Http\Controllers\Web\OrderController;
use App\Http\Controllers\Web\PresenceController;
use App\Http\Controllers\Web\ProductController;
use App\Http\Controllers\Web\ReportController;
use App\Http\Controllers\Web\Settings\BotController;
use App\Http\Controllers\Web\Settings\ChannelController;
use App\Http\Controllers\Web\Settings\CityController;
use App\Http\Controllers\Web\Settings\QuickReplyController;
use App\Http\Controllers\Web\Settings\TagController;
use App\Http\Controllers\Web\Settings\UserController;
use App\Http\Controllers\Web\SimulatorController;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackPresence;
use Illuminate\Support\Facades\Route;

Route::middleware([EnsureUserIsActive::class, SetLocale::class, TrackPresence::class])->group(function () {
    // Inbox
    Route::get('/inbox', [InboxController::class, 'index'])->name('inbox');
    Route::prefix('inbox')->name('inbox.')->group(function () {
        Route::get('conversations', [InboxController::class, 'list'])->middleware('record-list-latency')->name('conversations.index');
        Route::get('conversations/{conversation}', [InboxController::class, 'show'])->name('conversations.show');
        Route::get('conversations/{conversation}/messages', [InboxController::class, 'messages'])->name('conversations.messages.index');
        Route::post('conversations/{conversation}/messages', [InboxController::class, 'sendMessage'])->name('conversations.messages.store');
        Route::post('messages/{message}/retry', [InboxController::class, 'retryMessage'])->name('messages.retry');
        Route::post('conversations/{conversation}/typing', [InboxController::class, 'typing'])->name('conversations.typing');
        Route::post('conversations/{conversation}/notes', [InboxController::class, 'storeNote'])->name('conversations.notes.store');
        Route::post('conversations/{conversation}/resolve', [InboxController::class, 'resolve'])->name('conversations.resolve');
        Route::post('conversations/{conversation}/reopen', [InboxController::class, 'reopen'])->name('conversations.reopen');
        Route::post('conversations/{conversation}/return-to-bot', [InboxController::class, 'returnToBot'])->name('conversations.return-to-bot');
        Route::post('conversations/{conversation}/read', [InboxController::class, 'read'])->name('conversations.read');
        Route::post('conversations/{conversation}/priority', [InboxController::class, 'setPriority'])->name('conversations.priority');
        Route::post('conversations/{conversation}/tags', [InboxController::class, 'syncTags'])->name('conversations.tags');
        Route::post('conversations/{conversation}/orders', [InboxController::class, 'storeOrder'])->name('conversations.orders.store');
    });

    Route::get('/products/search', [ProductController::class, 'search'])->name('products.search');

    // Comments
    Route::get('/comments', [CommentController::class, 'index'])->name('comments.index');
    Route::get('/comments/feed', [CommentController::class, 'feed'])->name('comments.feed');
    Route::post('/comments/{comment}/reply', [CommentController::class, 'reply'])->name('comments.reply');
    Route::post('/comments/{comment}/hide', [CommentController::class, 'hide'])->name('comments.hide');
    Route::post('/comments/{comment}/private-reply', [CommentController::class, 'privateReply'])->name('comments.private-reply');

    // Orders
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
    Route::post('/orders/{order}/retry', [OrderController::class, 'retry'])->name('orders.retry');
    Route::middleware('role:supervisor')->group(function () {
        Route::post('/orders/{order}/mark-paid', [OrderController::class, 'markPaid'])->name('orders.mark-paid');
        Route::post('/orders/{order}/ship', [OrderController::class, 'ship'])->name('orders.ship');
    });

    // Customers
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    Route::get('/customers/{customer}/merge-suggestions', [CustomerController::class, 'mergeSuggestions'])->name('customers.merge-suggestions');
    Route::post('/customers/{customer}/merge', [CustomerController::class, 'merge'])->middleware('role:supervisor')->name('customers.merge');

    // Reports
    Route::get('/reports/me', [ReportController::class, 'me'])->name('reports.me');
    Route::middleware('role:supervisor')->group(function () {
        Route::get('/reports/team', [ReportController::class, 'team'])->name('reports.team');
        Route::get('/reports/users/{user}', [ReportController::class, 'user'])->name('reports.users.show');
        Route::get('/reports/bot', [ReportController::class, 'bot'])->name('reports.bot');
        Route::get('/reports/activity', [ReportController::class, 'activity'])->name('reports.activity');
    });
    Route::middleware('role:admin')->group(function () {
        Route::get('/reports/latency', [ReportController::class, 'latency'])->name('reports.latency');
    });

    // Settings — supervisor+
    Route::prefix('settings')->name('settings.')->middleware('role:supervisor')->group(function () {
        Route::get('bot', [BotController::class, 'index'])->name('bot.index');
        Route::put('bot', [BotController::class, 'update'])->name('bot.update');
        Route::post('bot/test', [BotController::class, 'test'])->name('bot.test');
        Route::post('bot/rules', [BotController::class, 'storeRule'])->name('bot.rules.store');
        Route::put('bot/rules/{rule}', [BotController::class, 'updateRule'])->name('bot.rules.update');
        Route::delete('bot/rules/{rule}', [BotController::class, 'destroyRule'])->name('bot.rules.destroy');

        Route::get('quick-replies', [QuickReplyController::class, 'index'])->name('quick-replies.index');
        Route::post('quick-replies', [QuickReplyController::class, 'store'])->name('quick-replies.store');
        Route::put('quick-replies/{quickReply}', [QuickReplyController::class, 'update'])->name('quick-replies.update');
        Route::delete('quick-replies/{quickReply}', [QuickReplyController::class, 'destroy'])->name('quick-replies.destroy');

        Route::get('tags', [TagController::class, 'index'])->name('tags.index');
        Route::post('tags', [TagController::class, 'store'])->name('tags.store');
        Route::put('tags/{tag}', [TagController::class, 'update'])->name('tags.update');
        Route::delete('tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');
    });

    // Settings — admin
    Route::prefix('settings')->name('settings.')->middleware('role:admin')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        Route::get('channels', [ChannelController::class, 'index'])->name('channels.index');
        Route::post('channels', [ChannelController::class, 'store'])->name('channels.store');
        Route::post('channels/webhook-events/{event}/reprocess', [ChannelController::class, 'reprocess'])->name('channels.webhook-events.reprocess');
        Route::put('channels/{channel}', [ChannelController::class, 'update'])->name('channels.update');
        Route::delete('channels/{channel}', [ChannelController::class, 'destroy'])->name('channels.destroy');
        Route::post('channels/{channel}/test', [ChannelController::class, 'test'])->name('channels.test');
        Route::post('channels/{channel}/subscribe', [ChannelController::class, 'subscribe'])->name('channels.subscribe');

        Route::get('cities', [CityController::class, 'index'])->name('cities.index');
        Route::post('cities', [CityController::class, 'store'])->name('cities.store');
        Route::put('cities/{city}', [CityController::class, 'update'])->name('cities.update');
        Route::delete('cities/{city}', [CityController::class, 'destroy'])->name('cities.destroy');
    });

    // Simulator — admin
    Route::prefix('simulator')->name('simulator.')->middleware('role:admin')->group(function () {
        Route::get('/', [SimulatorController::class, 'index'])->name('index');
        Route::post('message', [SimulatorController::class, 'message'])->name('message');
        Route::post('comment', [SimulatorController::class, 'comment'])->name('comment');
        Route::post('burst', [SimulatorController::class, 'burst'])->name('burst');
        Route::post('orders/{order}/pay', [SimulatorController::class, 'pay'])->name('orders.pay');
        Route::post('shipments/{shipment}/advance', [SimulatorController::class, 'advance'])->name('shipments.advance');
    });

    Route::post('/locale/{locale}', [LocaleController::class, 'update'])->whereIn('locale', SetLocale::SUPPORTED)->name('locale.update');
    Route::post('/presence/heartbeat', [PresenceController::class, 'heartbeat'])->name('presence.heartbeat');
});
