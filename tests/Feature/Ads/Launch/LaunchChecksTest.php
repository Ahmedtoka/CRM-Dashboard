<?php

use App\Ads\AdsSettings;
use App\Ads\Control\PublishService;
use App\Ads\Control\Write\WriteActionService;
use App\Ads\Launch\CheckResult;
use App\Ads\Launch\LaunchChecks;
use App\Ads\Launch\LaunchState;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Models\Ad;
use App\Models\AdPublication;
use App\Models\MediaBuyer;
use App\Models\ProductVariant;
use Tests\Support\FakeLandingProbe;
use Tests\Support\LaunchWorld;

beforeEach(fn () => LaunchWorld::boot());

function lcLevels(array $results): array
{
    return collect($results)->mapWithKeys(fn (CheckResult $c) => [$c->key => $c->level])->all();
}

it('passes a clean draft at submit, with both languages filled', function () {
    $w = LaunchWorld::make();
    $results = app(LaunchChecks::class)->run(LaunchWorld::launch($w), 'submit', $w['content']);

    expect(LaunchChecks::blocking($results))->toBe([])->and(LaunchChecks::warnings($results))->toBe([])
        ->and(array_keys(lcLevels($results)))->toBe([
            'slot_open', 'buyer_holds_account', 'account_writable', 'stock', 'product_active', 'landing_host', 'utm', 'caption_price', 'media', 'duplicate',
            'aspect_ratio', 'caption_length', 'cta_objective', 'sizes_in_stock', 'live_other_buyer', 'naming', 'parent_paused', 'landing_http',
        ]);
    $price = collect($results)->firstWhere('key', 'caption_price');
    expect($price->message_ar)->not->toBe('ads.launch.checks.caption_price.pass')->and($price->message_en)->toContain('450');
});

it('blocks a wrong caption price, a closed slot, no stock, an inactive product and a missing thumbnail', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::Draft, ['captions' => [['headline' => 'H', 'primary_text' => 'بـ 500 جنيه بس', 'cta' => 'SHOP_NOW']]]);
    $w['adset']->forceFill(['open_for_drafts_at' => null])->save();
    ProductVariant::query()->update(['inventory_quantity' => 0]);
    $w['product']->update(['status' => 'draft']);
    $w['files'][0]->update(['thumb_path' => null]);

    $levels = lcLevels(app(LaunchChecks::class)->run($l->fresh(), 'submit'));
    expect($levels['caption_price'])->toBe('block')->and($levels['slot_open'])->toBe('block')->and($levels['stock'])->toBe('block')
        ->and($levels['product_active'])->toBe('block')->and($levels['media'])->toBe('block');
});

it('blocks a duplicate of an ad still in flight for another launch or a direct publish', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w);
    AdPublication::create([
        'ad_material_id' => $w['material']->id, 'ad_material_file_id' => $w['files'][0]->id, 'ad_account_id' => $w['account']->id, 'platform' => 'meta',
        'campaign_external_id' => $l->campaign_external_id, 'adset_external_id' => $l->adset_external_id, 'headline' => LaunchWorld::caption()['headline'],
        'primary_text' => LaunchWorld::caption()['primary_text'], 'cta' => 'SHOP_NOW', 'ad_name' => 'x', 'link' => (string) $l->link, 'url_tags' => 'u', 'status' => 'queued',
        'open_key' => PublishService::openKey($w['account'], (string) $l->adset_external_id, $w['files'][0]->id, LaunchWorld::caption()),
    ]);

    expect(lcLevels(app(LaunchChecks::class)->run($l, 'forward'))['duplicate'])->toBe('block');
});

it('warns on length, ratio, CTA, low sizes, another buyer live, naming, paused parent and a dead landing', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::Draft, [
        'file_ids' => [$w['files'][0]->id],
        'captions' => [['headline' => str_repeat('ع', 41), 'primary_text' => str_repeat('ب', 126), 'cta' => 'SEND_MESSAGE']],
    ]);
    $w['files'][0]->update(['width' => 1000, 'height' => 1000]);
    ProductVariant::query()->first()->update(['inventory_quantity' => 0]);
    $w['campaign']->update(['name' => 'random', 'status' => 'PAUSED']);
    FakeLandingProbe::$status = 404;
    $other = MediaBuyer::factory()->create();
    LaunchWorld::launch($w, LaunchState::Live, ['reviewer_buyer_id' => $other->id]);

    $levels = lcLevels(app(LaunchChecks::class)->run($l->fresh(), 'submit'));
    foreach (['caption_length', 'aspect_ratio', 'cta_objective', 'sizes_in_stock', 'live_other_buyer', 'naming', 'parent_paused', 'landing_http'] as $key) {
        expect($levels[$key])->toBe('warn', $key);
    }
    expect(LaunchChecks::blocking(app(LaunchChecks::class)->run($l->fresh(), 'submit')))->toBe([]);
});

it('adds the approval checks: paused ad present, not disapproved, activations left, writes on; review pending warns', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::AwaitingApproval);
    $checks = app(LaunchChecks::class);

    $levels = lcLevels($checks->run($l, 'approve', $w['manager']));
    expect($levels)->not->toHaveKey('slot_open')
        ->and($levels['ap.paused_exists'])->toBe('pass')->and($levels['ap.not_disapproved'])->toBe('pass')
        ->and($levels['ap.activations_left'])->toBe('pass')->and($levels['ap.writes_on'])->toBe('pass')->and($levels['meta_review_pending'])->toBe('pass');

    Ad::query()->update(['effective_status' => 'DISAPPROVED']);
    app(AdsSettings::class)->set('writes_enabled', false);
    $levels = lcLevels($checks->run($l, 'approve', $w['manager']));
    expect($levels['ap.not_disapproved'])->toBe('block')->and($levels['ap.writes_on'])->toBe('block');

    Ad::query()->update(['status' => 'DELETED', 'effective_status' => 'PENDING_REVIEW']);
    $levels = lcLevels($checks->run($l, 'approve', $w['manager']));
    expect($levels['ap.paused_exists'])->toBe('block')->and($levels['meta_review_pending'])->toBe('warn');
});

it('hashes revision and stable checks only: price changes the hash, a landing flap or the approver count do not', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::AwaitingApproval);
    $checks = app(LaunchChecks::class);
    $hash = fn () => LaunchChecks::hash($l->fresh(), $checks->run($l->fresh(), 'approve', $w['manager']));
    $before = $hash();

    FakeLandingProbe::$status = 500;
    expect($hash())->toBe($before);

    ProductVariant::query()->first()->update(['inventory_quantity' => 7]); // still "ok" bucket
    expect($hash())->toBe($before);

    ProductVariant::query()->update(['price' => 500]);
    expect($hash())->not->toBe($before);
});

it('reads the budget cap and the parents live at approve', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::AwaitingApproval);
    $ext = $l->publications()->value('external_ad_id');

    $levels = lcLevels(app(LaunchChecks::class)->live($l, $w['manager']));
    expect($levels)->toBe(['ap.budget_cap' => 'pass', 'parent_paused' => 'pass']);

    app(FakeAdsDriver::class)->seedObject('ad', $ext, ['parents' => [
        ['level' => 'adset', 'status' => 'PAUSED', 'dailyBudgetMinor' => 5_000_000, 'lifetimeBudgetMinor' => null, 'endsAt' => null],
        ['level' => 'campaign', 'status' => 'ACTIVE', 'dailyBudgetMinor' => null, 'lifetimeBudgetMinor' => null, 'endsAt' => null],
    ]]);
    $levels = lcLevels(app(LaunchChecks::class)->live($l, $w['manager']));
    expect($levels)->toBe(['ap.budget_cap' => 'block', 'parent_paused' => 'warn']);
});

it('counts the activations a user and an account have left today', function () {
    $w = LaunchWorld::make();
    $left = app(WriteActionService::class)->activationsLeft($w['manager'], $w['account']);

    expect($left)->toBe(['user' => 20, 'account' => 30])
        ->and(app(WriteActionService::class)->activationsLeftToday($w['manager']))->toBe(20);
});
