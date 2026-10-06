<?php

use App\Http\Controllers\Web\Ads\AccountController;
use App\Http\Controllers\Web\Ads\ActionController;
use App\Http\Controllers\Web\Ads\AdDrawerController;
use App\Http\Controllers\Web\Ads\AdStockController;
use App\Http\Controllers\Web\Ads\AlertController;
use App\Http\Controllers\Web\Ads\ApprovalController;
use App\Http\Controllers\Web\Ads\BuyerController;
use App\Http\Controllers\Web\Ads\BuyerSetupController;
use App\Http\Controllers\Web\Ads\CaptionController;
use App\Http\Controllers\Web\Ads\ChatFunnelController;
use App\Http\Controllers\Web\Ads\CreativeController;
use App\Http\Controllers\Web\Ads\LaunchController;
use App\Http\Controllers\Web\Ads\DecisionsController;
use App\Http\Controllers\Web\Ads\ExplorerController;
use App\Http\Controllers\Web\Ads\LegacyAdsRedirectController;
use App\Http\Controllers\Web\Ads\MaterialCollectionController;
use App\Http\Controllers\Web\Ads\MaterialController;
use App\Http\Controllers\Web\Ads\NumbersController;
use App\Http\Controllers\Web\Ads\PublishController;
use App\Http\Controllers\Web\Ads\ReauthController;
use App\Http\Controllers\Web\Ads\RulesSetupController;
use App\Http\Controllers\Web\Ads\SlotController;
use App\Http\Controllers\Web\Ads\SyncController;
use App\Http\Controllers\Web\Ads\TodayController;
use App\Http\Controllers\Web\Ads\WriteActionController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

// Ads Hub. Required from routes/crm.php inside the authenticated group (see EnsureAdsAccess for the `ads:*` areas).
Route::middleware('ads:report')->group(function () {
    Route::get('/ads', TodayController::class)->name('ads.today');
    Route::get('/ads/decisions', DecisionsController::class)->name('ads.decisions');
    // Decisions feed card actions (S5).
    Route::post('/ads/alerts/seen', [AlertController::class, 'seen'])->middleware('throttle:60,1')->name('ads.alerts.seen');
    Route::post('/ads/alerts/snooze', [AlertController::class, 'snooze'])->middleware('throttle:60,1')->name('ads.alerts.snooze');
    Route::post('/ads/alerts/dismiss', [AlertController::class, 'dismiss'])->middleware('throttle:60,1')->name('ads.alerts.dismiss');
    Route::get('/ads/explorer', ExplorerController::class)->name('ads.explorer');
    Route::get('/ads/numbers', NumbersController::class)->name('ads.numbers');
    Route::get('/ads/buyers/{buyer}', [BuyerController::class, 'show'])->name('ads.buyers.show');
    Route::get('/ads/creatives/{ad}', [CreativeController::class, 'show'])->name('ads.creatives.show');
    // Old Ads Hub pages (bookmarks, notifications): redirect to the control-room page that replaced them, names kept.
    Route::get('/ads/creatives', [LegacyAdsRedirectController::class, 'creatives'])->name('ads.creatives.index');
    Route::get('/ads/winners', [LegacyAdsRedirectController::class, 'winners'])->name('ads.winners');
    Route::get('/ads/campaigns', [LegacyAdsRedirectController::class, 'campaigns'])->name('ads.campaigns');
    Route::get('/ads/actions', [LegacyAdsRedirectController::class, 'actions'])->name('ads.actions');
    Route::get('/ads/buyers', [LegacyAdsRedirectController::class, 'buyers'])->name('ads.buyers.index');
    Route::get('/ads/ad/{ad}', AdDrawerController::class)->name('ads.ad.show');
    // Control room S3: the chat funnel per ad for the ad drawer (out-of-scope ads are left out).
    Route::get('/ads/chat-funnel', ChatFunnelController::class)->name('ads.chat-funnel');
    Route::post('/ads/actions/status', [ActionController::class, 'status'])->name('ads.actions.status');

    // Phase B write pipeline (B2): propose, then confirm. Refusals use the stable code shape (WriteDenied).
    Route::post('/ads/write-actions', [WriteActionController::class, 'store'])->middleware('throttle:ads-writes')->name('ads.write-actions.store');
    Route::get('/ads/write-actions/{action}', [WriteActionController::class, 'show'])->name('ads.write-actions.show');
    Route::post('/ads/write-actions/{action}/confirm', [WriteActionController::class, 'confirm'])->middleware('throttle:ads-writes')->name('ads.write-actions.confirm');
    Route::post('/ads/write-actions/{action}/cancel', [WriteActionController::class, 'cancel'])->name('ads.write-actions.cancel');
    Route::post('/ads/reauth', ReauthController::class)->middleware('throttle:6,1')->name('ads.reauth');
    Route::post('/ads/write-actions/{action}/rollback', [WriteActionController::class, 'rollback'])->middleware('throttle:ads-writes')->name('ads.write-actions.rollback');

    // Launch approvals (control room S1): open slots.
    Route::get('/ads/slots', [SlotController::class, 'index'])->name('ads.slots.index');
    Route::post('/ads/slots/{adSet}', [SlotController::class, 'toggle'])->name('ads.slots.toggle');

    // Launch approvals (S1): the manager's queue. Approve / bulk need a password confirmed in the last 15 minutes (G3).
    Route::get('/ads/approvals', [ApprovalController::class, 'index'])->name('ads.approvals.index');
    Route::post('/ads/approvals/bulk', [ApprovalController::class, 'bulk'])
        ->middleware(['ads:authority', RequirePassword::using(null, ApprovalController::REAUTH_SECONDS)])->name('ads.approvals.bulk');
    Route::post('/ads/approvals/{launch}/approve', [ApprovalController::class, 'approve'])
        ->middleware(['ads:authority', RequirePassword::using(null, ApprovalController::REAUTH_SECONDS)])->name('ads.approvals.approve');
    Route::post('/ads/approvals/{launch}/return', [ApprovalController::class, 'sendBack'])->name('ads.approvals.return');
    Route::post('/ads/approvals/{launch}/reject', [ApprovalController::class, 'reject'])->name('ads.approvals.reject');
});

Route::middleware('ads:manage')->group(function () {
    Route::get('/ads/sync', [SyncController::class, 'index'])->name('ads.sync');
    Route::get('/ads/accounts', [AccountController::class, 'index'])->name('ads.accounts.index');
    Route::post('/ads/connections', [AccountController::class, 'store'])->name('ads.connections.store');
    Route::put('/ads/connections/{connection}', [AccountController::class, 'update'])->name('ads.connections.update');
    Route::post('/ads/connections/{connection}/test', [AccountController::class, 'test'])->name('ads.connections.test');
    Route::post('/ads/connections/{connection}/sync', [AccountController::class, 'sync'])->name('ads.connections.sync');
    Route::delete('/ads/connections/{connection}', [AccountController::class, 'destroy'])->name('ads.connections.destroy');
    Route::post('/ads/accounts/{account}/assign', [AccountController::class, 'assign'])->name('ads.accounts.assign');
    Route::patch('/ads/accounts/{account}', [AccountController::class, 'updateAccount'])->name('ads.accounts.update');
    Route::post('/ads/accounts/{account}/sync', [AccountController::class, 'syncAccount'])->name('ads.accounts.sync');

    // «الإعداد» tabs: الحسابات (/ads/accounts), المزامنة (/ads/sync), الميديا باير, القواعد.
    Route::redirect('/ads/setup', '/ads/accounts')->name('ads.setup');
    Route::get('/ads/setup/rules', [BuyerSetupController::class, 'rules'])->name('ads.setup.rules');
    Route::get('/ads/setup/buyers', [BuyerSetupController::class, 'index'])->name('ads.setup.buyers');
    Route::post('/ads/setup/buyers', [BuyerSetupController::class, 'store'])->name('ads.setup.buyers.store');
    Route::put('/ads/setup/buyers/{buyer}', [BuyerSetupController::class, 'update'])->name('ads.setup.buyers.update');
    Route::delete('/ads/setup/buyers/{buyer}', [BuyerSetupController::class, 'destroy'])->name('ads.setup.buyers.destroy');
    Route::put('/ads/setup/buyers/{buyer}/targets', [BuyerSetupController::class, 'targets'])->name('ads.setup.buyers.targets');
    Route::put('/ads/setup/settings', [BuyerSetupController::class, 'settings'])->name('ads.setup.settings');
    Route::put('/ads/setup/rules', [RulesSetupController::class, 'update'])->name('ads.setup.rules.update');
    Route::put('/ads/setup/rules/notify', [RulesSetupController::class, 'notify'])->name('ads.setup.rules.notify');
});

// Materials library: content, media buyers and supervisors (write permissions are checked per action).
Route::middleware('ads:materials')->group(function () {
    Route::get('/ads/materials', [MaterialController::class, 'index'])->name('ads.materials.index');
    Route::get('/ads/materials/create', [MaterialController::class, 'create'])->name('ads.materials.create');
    Route::get('/ads/materials/export', [MaterialController::class, 'export'])->name('ads.materials.export');
    Route::get('/ads/materials/ad-search', [MaterialController::class, 'adSearch'])->name('ads.materials.ad-search');
    Route::get('/ads/materials/files/{file}', [MaterialController::class, 'file'])->name('ads.materials.files.show');
    Route::get('/ads/materials/files/{file}/thumb', [MaterialController::class, 'thumb'])->name('ads.materials.files.thumb');
    Route::post('/ads/materials', [MaterialController::class, 'store'])->name('ads.materials.store');
    Route::get('/ads/materials/{material}/edit', [MaterialController::class, 'edit'])->name('ads.materials.edit');
    Route::put('/ads/materials/{material}', [MaterialController::class, 'update'])->name('ads.materials.update');
    Route::delete('/ads/materials/{material}', [MaterialController::class, 'destroy'])->name('ads.materials.destroy');
    Route::post('/ads/materials/{material}/ads', [MaterialController::class, 'syncAds'])->name('ads.materials.ads');
    Route::get('/ads/products/search', [MaterialController::class, 'productSearch'])->name('ads.products.search');
    Route::get('/ads/publish/options', [PublishController::class, 'options'])->name('ads.publish.options');
    Route::post('/ads/materials/{material}/publish', [PublishController::class, 'publish'])->name('ads.materials.publish');
    Route::get('/ads/materials/{material}/captions', [CaptionController::class, 'index'])->name('ads.materials.captions');
    // Each call is a paid AI request: at most 10 a minute per user.
    Route::post('/ads/materials/{material}/captions', [CaptionController::class, 'generate'])->middleware('throttle:10,1')->name('ads.materials.captions.generate');
    Route::put('/ads/captions/{caption}', [CaptionController::class, 'update'])->name('ads.captions.update');
    Route::get('/ads/materials/{material}/publications', [PublishController::class, 'index'])->name('ads.materials.publications');

    Route::get('/ads/collections', [MaterialCollectionController::class, 'index'])->name('ads.collections.index');
    Route::post('/ads/collections', [MaterialCollectionController::class, 'store'])->name('ads.collections.store');
    Route::put('/ads/collections/{collection}', [MaterialCollectionController::class, 'update'])->name('ads.collections.update');
    Route::delete('/ads/collections/{collection}', [MaterialCollectionController::class, 'destroy'])->name('ads.collections.destroy');

    Route::get('/ads/stock', [AdStockController::class, 'index'])->name('ads.stock.index');
    Route::get('/ads/stock/export', [AdStockController::class, 'export'])->name('ads.stock.export');
    Route::post('/ads/stock/{material}/availability', [AdStockController::class, 'availability'])->name('ads.stock.availability');
});

// Launch approvals (control room S1): drafts and buyer review — content, buyers and supervisor+ (policy per launch).
Route::middleware('ads:materials')->group(function () {
    Route::get('/ads/launches', [LaunchController::class, 'index'])->name('ads.launches.index');
    Route::get('/ads/launches/options', [LaunchController::class, 'options'])->name('ads.launches.options');
    Route::get('/ads/launches/{launch}', [LaunchController::class, 'show'])->name('ads.launches.show');
    Route::get('/ads/launches/{launch}/checks', [LaunchController::class, 'checks'])->name('ads.launches.checks');
    Route::post('/ads/materials/{material}/launches', [LaunchController::class, 'store'])->name('ads.launches.store');
    Route::put('/ads/launches/{launch}', [LaunchController::class, 'update'])->name('ads.launches.update');
    Route::post('/ads/launches/{launch}/submit', [LaunchController::class, 'submit'])->name('ads.launches.submit');
    Route::post('/ads/launches/{launch}/send-back', [LaunchController::class, 'sendBack'])->name('ads.launches.send-back');
    Route::post('/ads/launches/{launch}/withdraw', [LaunchController::class, 'withdraw'])->name('ads.launches.withdraw');
    Route::post('/ads/launches/{launch}/forward', [LaunchController::class, 'forward'])->name('ads.launches.forward');
    Route::post('/ads/launches/{launch}/retry', [LaunchController::class, 'retry'])->name('ads.launches.retry');
    Route::post('/ads/launches/{launch}/stop', [LaunchController::class, 'stop'])->name('ads.launches.stop');
    Route::post('/ads/launches/{launch}/retire', [LaunchController::class, 'retire'])->name('ads.launches.retire');
    Route::post('/ads/materials/{material}/retire', [MaterialController::class, 'retire'])->name('ads.materials.retire');
});
