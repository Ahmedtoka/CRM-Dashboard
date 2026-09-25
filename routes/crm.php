<?php

// CRM application routes (loaded with web + auth middleware in bootstrap/app.php).

use App\Http\Controllers\Web\CaseController;
use App\Http\Controllers\Web\CommentController;
use App\Http\Controllers\Web\CustomerController;
use App\Http\Controllers\Web\InboxController;
use App\Http\Controllers\Web\LocaleController;
use App\Http\Controllers\Web\MediaController;
use App\Http\Controllers\Web\NotificationController;
use App\Http\Controllers\Web\OrderController;
use App\Http\Controllers\Web\PresenceController;
use App\Http\Controllers\Web\ProductController;
use App\Http\Controllers\Web\QuickReplyAttachmentController;
use App\Http\Controllers\Web\ReportController;
use App\Http\Controllers\Web\SearchController;
use App\Http\Controllers\Web\Settings\BotController;
use App\Http\Controllers\Web\Settings\BotFlowController;
use App\Http\Controllers\Web\Settings\BotFlowSandboxController;
use App\Http\Controllers\Web\Settings\BotIntentController;
use App\Http\Controllers\Web\Settings\BotKnowledgeController;
use App\Http\Controllers\Web\Settings\BotLearningController;
use App\Http\Controllers\Web\Settings\BotReplyController;
use App\Http\Controllers\Web\Settings\BotTestLinkController;
use App\Http\Controllers\Web\Settings\BotTranslationController;
use App\Http\Controllers\Web\Settings\BranchController;
use App\Http\Controllers\Web\Settings\ChannelController;
use App\Http\Controllers\Web\Settings\CityController;
use App\Http\Controllers\Web\Settings\FacebookLoginController;
use App\Http\Controllers\Web\Settings\IntegrationController;
use App\Http\Controllers\Web\Settings\QuickReplyCategoryController;
use App\Http\Controllers\Web\Settings\QuickReplyController;
use App\Http\Controllers\Web\Settings\ShopifyIntegrationController;
use App\Http\Controllers\Web\Settings\TagController;
use App\Http\Controllers\Web\Settings\UserController;
use App\Http\Controllers\Web\ShippingController;
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
        Route::get('conversations/{conversation}/media', [InboxController::class, 'media'])->name('conversations.media.index');
        Route::post('conversations/{conversation}/attachments', [InboxController::class, 'uploadAttachment'])->name('conversations.attachments.store');
        Route::post('messages/{message}/retry', [InboxController::class, 'retryMessage'])->name('messages.retry');
        Route::post('conversations/{conversation}/typing', [InboxController::class, 'typing'])->name('conversations.typing');
        Route::post('conversations/{conversation}/notes', [InboxController::class, 'storeNote'])->name('conversations.notes.store');
        Route::post('conversations/{conversation}/claim', [InboxController::class, 'claim'])->name('conversations.claim');
        Route::get('conversations/{conversation}/mentionable', [InboxController::class, 'mentionable'])->name('conversations.mentionable');
        Route::post('conversations/{conversation}/resolve', [InboxController::class, 'resolve'])->name('conversations.resolve');
        Route::post('conversations/{conversation}/reopen', [InboxController::class, 'reopen'])->name('conversations.reopen');
        Route::post('conversations/{conversation}/return-to-bot', [InboxController::class, 'returnToBot'])->name('conversations.return-to-bot');
        Route::post('conversations/{conversation}/reset', [InboxController::class, 'reset'])->name('conversations.reset');
        Route::post('conversations/{conversation}/read', [InboxController::class, 'read'])->name('conversations.read');
        Route::post('conversations/{conversation}/priority', [InboxController::class, 'setPriority'])->name('conversations.priority');
        Route::post('conversations/{conversation}/tags', [InboxController::class, 'syncTags'])->name('conversations.tags');
        Route::post('conversations/{conversation}/orders', [InboxController::class, 'storeOrder'])->name('conversations.orders.store');
        Route::post('conversations/{conversation}/quick-replies/{quickReply}/render', [InboxController::class, 'renderQuickReply'])->name('conversations.quick-replies.render');
    });

    Route::get('/products/search', [ProductController::class, 'search'])->name('products.search');

    // Global search (Dashboard Experience Task 13, spec §5.3): command palette data source.
    Route::get('/search', SearchController::class)->middleware('throttle:60,1')->name('search');

    // Notifications (Dashboard Experience Task 14, spec §5.4): bell data source + mark-read.
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read', [NotificationController::class, 'read'])->name('notifications.read');

    // Media (Dashboard Experience Task 1): authorised inline/download serving + inbound retry.
    Route::get('/media/{attachment}', [MediaController::class, 'show'])->name('media.show');
    Route::post('/media/{attachment}/retry', [MediaController::class, 'retry'])->name('media.retry');

    // Saved replies (Dashboard Experience Task 4): a reply's own attachment thumbnail/download.
    Route::get('/quick-reply-attachments/{attachment}', [QuickReplyAttachmentController::class, 'show'])->name('quick-reply-attachments.show');

    // Bot size-chart image (Dashboard Experience Task 10): any signed-in user, since
    // Task 11 has the bot hand this same image to customers as an outbound attachment.
    Route::get('/bot/size-chart-image', [BotKnowledgeController::class, 'showSizeChartImage'])->name('bot.size-chart-image');

    // Shipping (order drawer): province list + rate quote (spec §5.1).
    Route::get('/shipping/provinces', [ShippingController::class, 'provinces'])->name('shipping.provinces');
    Route::get('/shipping/quote', [ShippingController::class, 'quote'])->name('shipping.quote');

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

    // Support cases (spec §4)
    Route::get('/cases', [CaseController::class, 'index'])->name('cases.index');
    Route::get('/cases/{supportCase}', [CaseController::class, 'show'])->name('cases.show');
    Route::patch('/cases/{supportCase}', [CaseController::class, 'update'])->name('cases.update');

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
        Route::get('/reports/ads', [ReportController::class, 'ads'])->name('reports.ads');
        Route::get('/reports/activity', [ReportController::class, 'activity'])->name('reports.activity');
        Route::get('/reports/quick-replies', [ReportController::class, 'quickReplies'])->name('reports.quick-replies');
        Route::get('/reports/quick-replies/export', [ReportController::class, 'quickRepliesExport'])->name('reports.quick-replies.export');

        // Reports → «تجربة الفريق» (design 2026-09-21 §4): the team's own runs of the
        // public test links — sessions, funnels, transcripts and the CSV.
        Route::get('/reports/team-test', [ReportController::class, 'teamTest'])->name('reports.team-test');
        Route::get('/reports/team-test/export', [ReportController::class, 'teamTestExport'])->name('reports.team-test.export');
        Route::get('/reports/team-test/sessions/{session}', [ReportController::class, 'teamTestSession'])->name('reports.team-test.session');
    });
    Route::middleware(['dev-tools', 'role:admin'])->group(function () {
        Route::get('/reports/latency', [ReportController::class, 'latency'])->name('reports.latency');
    });

    // Saved replies (Dashboard Experience Task 5, spec §2.3): any signed-in
    // user reaches the settings page — shared read-only unless supervisor+,
    // personal always manageable by its owner — every mutation still goes
    // through QuickReplyPolicy inside the controller.
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('quick-replies', [QuickReplyController::class, 'index'])->name('quick-replies.index');
        Route::post('quick-replies', [QuickReplyController::class, 'store'])->name('quick-replies.store');
        Route::post('quick-replies/preview', [QuickReplyController::class, 'preview'])->name('quick-replies.preview');
        Route::put('quick-replies/{quickReply}', [QuickReplyController::class, 'update'])->name('quick-replies.update');
        Route::delete('quick-replies/{quickReply}', [QuickReplyController::class, 'destroy'])->name('quick-replies.destroy');
        Route::post('quick-replies/{quickReply}/attachments', [QuickReplyController::class, 'storeAttachment'])->name('quick-replies.attachments.store');
        Route::delete('quick-replies/{quickReply}/attachments/{attachment}', [QuickReplyController::class, 'destroyAttachment'])->name('quick-replies.attachments.destroy');
    });

    // Settings — supervisor+
    Route::prefix('settings')->name('settings.')->middleware('role:supervisor')->group(function () {
        Route::get('bot', [BotController::class, 'index'])->name('bot.index');
        Route::put('bot', [BotController::class, 'update'])->name('bot.update');

        // Intent catalog (human bot flow Task 5): routing/priority/queue/scripts per intent.
        Route::get('bot-intents', [BotIntentController::class, 'index'])->name('bot-intents.index');
        Route::patch('bot-intents/{intent}', [BotIntentController::class, 'update'])->name('bot-intents.update');

        // Every reply the bot can give, on one page (owner, 2026-09-22).
        Route::get('bot-replies', [BotReplyController::class, 'index'])->name('bot-replies.index');
        Route::put('bot-replies/text', [BotReplyController::class, 'updateText'])->name('bot-replies.text.update');
        Route::delete('bot-replies/text', [BotReplyController::class, 'resetText'])->name('bot-replies.text.reset');

        Route::get('bot-knowledge', [BotKnowledgeController::class, 'index'])->name('bot-knowledge.index');
        Route::post('bot-knowledge/entries', [BotKnowledgeController::class, 'storeEntry'])->name('bot-knowledge.entries.store');
        Route::put('bot-knowledge/entries/{entry}', [BotKnowledgeController::class, 'updateEntry'])->name('bot-knowledge.entries.update');
        Route::delete('bot-knowledge/entries/{entry}', [BotKnowledgeController::class, 'destroyEntry'])->name('bot-knowledge.entries.destroy');
        Route::put('bot-knowledge/size-chart', [BotKnowledgeController::class, 'updateSizeChart'])->name('bot-knowledge.size-chart.update');
        Route::post('bot-knowledge/size-chart/image', [BotKnowledgeController::class, 'storeSizeChartImage'])->name('bot-knowledge.size-chart.image.store');
        Route::delete('bot-knowledge/size-chart/image', [BotKnowledgeController::class, 'destroySizeChartImage'])->name('bot-knowledge.size-chart.image.destroy');
        Route::post('bot-knowledge/ask', [BotKnowledgeController::class, 'ask'])->middleware('throttle:30,1')->name('bot-knowledge.ask');

        // Empty-state starter set of shared replies (the page itself is open to every user).
        Route::post('quick-replies/examples', [QuickReplyController::class, 'examples'])->name('quick-replies.examples');

        Route::post('quick-reply-categories', [QuickReplyCategoryController::class, 'store'])->name('quick-reply-categories.store');
        Route::put('quick-reply-categories/{category}', [QuickReplyCategoryController::class, 'update'])->name('quick-reply-categories.update');
        Route::delete('quick-reply-categories/{category}', [QuickReplyCategoryController::class, 'destroy'])->name('quick-reply-categories.destroy');

        Route::get('tags', [TagController::class, 'index'])->name('tags.index');
        Route::post('tags', [TagController::class, 'store'])->name('tags.store');
        Route::post('tags/examples', [TagController::class, 'examples'])->name('tags.examples');
        Route::put('tags/{tag}', [TagController::class, 'update'])->name('tags.update');
        Route::delete('tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');

        // Branch directory (Task 2): store locations App\Bot\Flows\BranchFinder lists by area.
        Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
        Route::post('branches', [BranchController::class, 'store'])->name('branches.store');
        Route::patch('branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
        Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->name('branches.destroy');

        // Flow designer (2026-09-17 Task 2): drafts, publish, versions, create, main menu.
        // Task 3: the sandbox simulator (one simulated turn, nothing sent or saved).
        Route::get('bot-flows', [BotFlowController::class, 'index'])->name('bot-flows.index');
        Route::post('bot-flows', [BotFlowController::class, 'store'])->name('bot-flows.store');
        Route::get('bot-flows/{flow}', [BotFlowController::class, 'show'])->name('bot-flows.show');
        Route::patch('bot-flows/{flow}', [BotFlowController::class, 'update'])->name('bot-flows.update');
        Route::put('bot-flows/{flow}/draft', [BotFlowController::class, 'saveDraft'])->name('bot-flows.draft.save');
        Route::delete('bot-flows/{flow}/draft', [BotFlowController::class, 'discardDraft'])->name('bot-flows.draft.discard');
        Route::post('bot-flows/{flow}/publish', [BotFlowController::class, 'publish'])->name('bot-flows.publish');
        Route::post('bot-flows/{flow}/main-menu', [BotFlowController::class, 'addToMainMenu'])->name('bot-flows.main-menu');
        Route::post('bot-flow-versions/{version}/restore', [BotFlowController::class, 'restore'])->name('bot-flow-versions.restore');
        Route::post('bot-flows/{flow}/simulate', BotFlowSandboxController::class)->middleware('throttle:60,1')->name('bot-flows.simulate');

        // Bilingual bot (design 2026-09-21 §2): what the bot says in English, and who wrote it.
        Route::get('bot-translations', [BotTranslationController::class, 'index'])->name('bot-translations.index');
        Route::put('bot-translations/{translation}', [BotTranslationController::class, 'update'])->name('bot-translations.update');
        Route::post('bot-translations/retranslate', [BotTranslationController::class, 'retranslate'])->middleware('throttle:30,1')->name('bot-translations.retranslate');

        // Public test links for the team (design 2026-09-21 §1): create, copy, stop, delete.
        Route::get('bot-test-links', [BotTestLinkController::class, 'index'])->name('bot-test-links.index');
        Route::post('bot-test-links', [BotTestLinkController::class, 'store'])->name('bot-test-links.store');
        Route::patch('bot-test-links/{testLink}', [BotTestLinkController::class, 'update'])->name('bot-test-links.update');
        Route::delete('bot-test-links/{testLink}', [BotTestLinkController::class, 'destroy'])->name('bot-test-links.destroy');
        Route::get('bot-test-links/{testLink}/sessions', [BotTestLinkController::class, 'sessions'])->name('bot-test-links.sessions');

        // Daily learning (design §6, Task 9): nightly reports and the suggestions
        // that change nothing until the owner approves them here.
        Route::get('bot-learning', [BotLearningController::class, 'index'])->name('bot-learning.index');
        Route::post('bot-learning/run', [BotLearningController::class, 'run'])->middleware('throttle:6,1')->name('bot-learning.run');
        Route::post('bot-suggestions/{suggestion}/approve', [BotLearningController::class, 'approve'])->name('bot-suggestions.approve');
        Route::post('bot-suggestions/{suggestion}/reject', [BotLearningController::class, 'reject'])->name('bot-suggestions.reject');
    });

    // Settings — admin
    Route::prefix('settings')->name('settings.')->middleware('role:admin')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        // Integrations (owner-facing connection cards); Channels below is its "Advanced" page.
        Route::get('integrations', [IntegrationController::class, 'index'])->name('integrations.index');
        Route::middleware('throttle:30,1')->group(function () {
            Route::post('integrations/facebook/system-token', [IntegrationController::class, 'systemTokenPages'])->name('integrations.facebook.system-token');
            Route::post('integrations/facebook/system-token/connect', [IntegrationController::class, 'systemTokenConnect'])->name('integrations.facebook.system-token.connect');
            Route::get('integrations/instagram/discover', [IntegrationController::class, 'instagramDiscover'])->name('integrations.instagram.discover');
            Route::post('integrations/instagram/connect', [IntegrationController::class, 'instagramConnect'])->name('integrations.instagram.connect');
            Route::post('integrations/whatsapp/phone-numbers', [IntegrationController::class, 'whatsappPhoneNumbers'])->name('integrations.whatsapp.phone-numbers');
            Route::post('integrations/whatsapp/connect', [IntegrationController::class, 'whatsappConnect'])->name('integrations.whatsapp.connect');
            Route::post('integrations/{channel}/test', [IntegrationController::class, 'test'])->name('integrations.test');
            Route::post('integrations/{channel}/fix', [IntegrationController::class, 'fix'])->name('integrations.fix');
            Route::delete('integrations/{channel}', [IntegrationController::class, 'destroy'])->name('integrations.destroy');
        });

        Route::get('channels', [ChannelController::class, 'index'])->name('channels.index');
        Route::post('channels', [ChannelController::class, 'store'])->name('channels.store');
        Route::post('channels/webhook-events/{event}/reprocess', [ChannelController::class, 'reprocess'])->name('channels.webhook-events.reprocess');
        Route::put('channels/{channel}', [ChannelController::class, 'update'])->name('channels.update');
        Route::delete('channels/{channel}', [ChannelController::class, 'destroy'])->name('channels.destroy');
        Route::post('channels/{channel}/test', [ChannelController::class, 'test'])->name('channels.test');
        Route::post('channels/{channel}/subscribe', [ChannelController::class, 'subscribe'])->name('channels.subscribe');
        Route::get('channels/facebook/connect', [FacebookLoginController::class, 'connect'])->name('channels.facebook.connect');
        Route::get('channels/facebook/callback', [FacebookLoginController::class, 'callback'])->name('channels.facebook.callback');
        Route::get('channels/facebook/pages', [FacebookLoginController::class, 'pages'])->name('channels.facebook.pages');
        Route::post('channels/facebook/pages/{pageId}', [FacebookLoginController::class, 'select'])->where('pageId', '[0-9]+')->name('channels.facebook.select');

        Route::get('cities', [CityController::class, 'index'])->name('cities.index');
        Route::post('cities', [CityController::class, 'store'])->name('cities.store');
        Route::put('cities/{city}', [CityController::class, 'update'])->name('cities.update');
        Route::delete('cities/{city}', [CityController::class, 'destroy'])->name('cities.destroy');

        Route::get('shopify', [ShopifyIntegrationController::class, 'index'])->name('shopify.index');
        Route::get('shopify/status', [ShopifyIntegrationController::class, 'status'])->name('shopify.status');
        Route::post('shopify/test', [ShopifyIntegrationController::class, 'test'])->name('shopify.test');
        Route::post('shopify/connect', [ShopifyIntegrationController::class, 'connect'])->name('shopify.connect');
        Route::post('shopify/resume-import', [ShopifyIntegrationController::class, 'resumeImport'])->name('shopify.resume-import');
        Route::post('shopify/sync', [ShopifyIntegrationController::class, 'sync'])->name('shopify.sync');
        Route::post('shopify/webhooks/reregister', [ShopifyIntegrationController::class, 'reregisterWebhooks'])->name('shopify.webhooks.reregister');
        Route::put('shopify/settings', [ShopifyIntegrationController::class, 'updateSettings'])->name('shopify.settings.update');
        Route::delete('shopify', [ShopifyIntegrationController::class, 'destroy'])->name('shopify.destroy');
    });

    // Simulator — admin, developer tools only (crm.dev_tools)
    Route::prefix('simulator')->name('simulator.')->middleware(['dev-tools', 'role:admin'])->group(function () {
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
