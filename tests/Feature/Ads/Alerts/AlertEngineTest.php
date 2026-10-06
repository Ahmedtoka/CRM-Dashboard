<?php

use App\Ads\Alerts\AlertEngine;
use App\Ads\Alerts\Rule;
use App\Ads\Alerts\RuleRegistry;
use App\Ads\Alerts\Rules\OutOfStock;
use App\Ads\Alerts\Rules\PriceMismatch;
use App\Ads\Alerts\Rules\ProductUnavailable;
use App\Ads\Alerts\Rules\ReactivateRestocked;
use App\Ads\Alerts\Rules\SpendNoResult;
use App\Ads\Alerts\Rules\SpendSpikeToday;
use App\Models\AdsAlert;
use App\Models\AdsSyncRun;
use Tests\Support\AlertWorld as W;

beforeEach(fn () => W::freeze());

it('registers fourteen rules and keeps cpo_above_target off (R-08)', function () {
    $ids = collect(app(RuleRegistry::class)->all())->map(fn (Rule $r) => $r->id());

    expect($ids)->toHaveCount(14)->and($ids->unique())->toHaveCount(14)->and($ids)->not->toContain('msg.cpo_above_target')
        ->and(collect(app(RuleRegistry::class)->for(Rule::HOURLY))->map(fn (Rule $r) => $r->id())->sort()->values()->all())
        ->toBe(collect([OutOfStock::ID, SpendSpikeToday::ID, PriceMismatch::ID, ProductUnavailable::ID, ReactivateRestocked::ID])->sort()->values()->all());
});

it('keeps facts but skips performance rules when the account data is stale', function () {
    $acc = W::account();
    AdsSyncRun::query()->update(['finished_at' => now()->subHours(5)]);
    $ad = W::ad($acc);
    W::spendDays($ad, 2, 800);
    W::link($ad, W::product([0]));

    $r = app(AlertEngine::class)->evaluateAccount($acc, Rule::DAILY);

    expect($r['fresh'])->toBeFalse()->and($r['skipped'])->toContain(SpendNoResult::ID)
        ->and(AdsAlert::pluck('rule_id')->all())->toBe([OutOfStock::ID]);
});

it('asks for a replacement first when no healthy ad stays in the ad set', function () {
    $acc = W::account();
    $alone = W::ad($acc);
    W::spendDays($alone, 2, 800);

    app(AlertEngine::class)->evaluateAccount($acc, Rule::DAILY);

    $row = AdsAlert::where('rule_id', SpendNoResult::ID)->sole();
    expect($row->action)->toBe('add_replacement')->and($row->params['no_alternative'])->toBeTrue();
});

it('keeps Stop when a healthy ad remains in the ad set', function () {
    $acc = W::account();
    $ad = W::ad($acc);
    $sibling = W::ad($acc, 'OUTCOME_SALES', [], $ad->adSet);
    W::spendDays($ad, 2, 800);
    W::spendDays($sibling, 2, 800, -1, ['purchases' => 3, 'purchase_value' => 3000]);

    app(AlertEngine::class)->evaluateAccount($acc, Rule::DAILY);

    expect(AdsAlert::where('rule_id', SpendNoResult::ID)->sole()->action)->toBe('stop');
});

it('is idempotent across runs', function () {
    $acc = W::account();
    W::link(W::ad($acc), W::product([0]));
    $engine = app(AlertEngine::class);

    $first = $engine->evaluateAccount($acc, Rule::HOURLY);
    $second = $engine->evaluateAccount($acc, Rule::HOURLY);

    expect($first['opened'])->toHaveCount(1)->and($second['opened'])->toBe([])->and($second['refreshed'])->toBe(1)->and(AdsAlert::count())->toBe(1);
});

it('does not suggest scaling an ad that has a critical problem in the same run', function () {
    $acc = W::account();
    $ad = W::ad($acc);
    W::spendDays($ad, 14, 100, -2, ['purchases' => 2, 'purchase_value' => 500]);
    foreach (range(2, 15) as $d) {
        W::order($ad, W::day(-$d).' 12:00', 250);
        W::order($ad, W::day(-$d).' 13:00', 250);
    }
    W::link($ad, W::product([5], 900, ['status' => 'archived']));

    app(AlertEngine::class)->evaluateAccount($acc, Rule::DAILY);

    expect(AdsAlert::pluck('rule_id')->all())->toBe([ProductUnavailable::ID]);
});

it('judges a non-EGP account on facts only, with no money at risk (EGP rules)', function () {
    $acc = W::account(['currency' => 'USD']);
    $ad = W::ad($acc);
    W::spendDays($ad, 2, 800);
    W::link($ad, W::product([0]));

    $r = app(AlertEngine::class)->evaluateAccount($acc, Rule::DAILY);

    expect($r['skipped'])->toContain(SpendNoResult::ID)->toContain(SpendSpikeToday::ID)
        ->and(AdsAlert::pluck('rule_id')->all())->toBe([OutOfStock::ID])
        ->and((float) AdsAlert::sole()->money_at_risk_per_day)->toBe(0.0)
        ->and(AdsAlert::sole()->evidence['currency'])->toBe('USD');
});

function engSlowInbox(int $replyAfter): \App\Models\AdAccount
{
    app(\App\Ads\Alerts\RuleSettings::class)->saveInputs(W::authority(), null, ['target_cpo' => 300]);
    $acc = W::account();
    $ad = W::ad($acc, 'MESSAGES');
    W::spendDays($ad, 14, 80, -3);
    W::fakeChats([$ad->id => ['chats' => 20, 'orders' => 0]]);
    foreach (range(1, 20) as $i) {
        $r = W::referral($ad, W::day(-10).' 12:00');
        \App\Models\Message::factory()->create(['conversation_id' => $r->conversation_id, 'direction' => 'out', 'sender_type' => 'user',
            'created_at' => \Carbon\CarbonImmutable::parse(W::day(-10).' 12:00', 'Africa/Cairo')->addMinutes($replyAfter)->utc()]);
    }

    return $acc;
}

it('caps a messages performance stop at medium when the inbox was slow for its chats (catalogue 1.4)', function () {
    app(AlertEngine::class)->evaluateAccount(engSlowInbox(30), Rule::DAILY);

    $row = AdsAlert::where('rule_id', 'msg.chats_no_orders')->sole();
    expect($row->severity)->toBe('medium')->and($row->params['inbox_slow'])->toBeTrue()
        ->and($row->evidence['inbox']['median_minutes'])->toEqual(30);
});

it('keeps the severity when the inbox answered fast', function () {
    app(AlertEngine::class)->evaluateAccount(engSlowInbox(3), Rule::DAILY);

    $row = AdsAlert::where('rule_id', 'msg.chats_no_orders')->sole();
    expect($row->severity)->toBe('high')->and($row->params)->not->toHaveKey('inbox_slow');
});
