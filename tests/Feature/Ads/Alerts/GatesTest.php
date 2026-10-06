<?php

use App\Ads\Alerts\AlertData;
use App\Ads\Alerts\Family;
use App\Ads\Alerts\Gates;
use App\Ads\Alerts\RuleContext;
use App\Models\AdsSyncRun;
use Tests\Support\AlertWorld as W;

beforeEach(fn () => W::freeze());

it('calls an account fresh up to three hours after its last ok sync', function (int $minutes, bool $ok) {
    $acc = W::account();
    AdsSyncRun::query()->where('ad_account_id', $acc->id)->update(['finished_at' => now()->subMinutes($minutes)]);

    expect(app(Gates::class)->dataFresh($acc, W::freeze(), app(AlertData::class))['ok'])->toBe($ok);
})->with([[174, true], [180, true], [186, false]]);

it('treats a never synced account as not fresh', function () {
    $acc = W::account();
    AdsSyncRun::query()->delete();

    expect(app(Gates::class)->dataFresh($acc, W::freeze(), app(AlertData::class)))->toBe(['ok' => false, 'last_ok_at' => null, 'age_hours' => null]);
});

it('knows when Meta barely delivers an ad and when it is still learning', function () {
    $g = app(Gates::class);

    expect($g->barelyDelivered(99, 1000))->toBeTrue()->and($g->barelyDelivered(100, 1000))->toBeFalse()->and($g->barelyDelivered(0, 0))->toBeFalse()
        ->and($g->learning(W::day(-6), W::day(0)))->toBeTrue()->and($g->learning(W::day(-7), W::day(0)))->toBeFalse()->and($g->learning(null, W::day(0)))->toBeFalse();
});

it('finds a healthy alternative only in the same ad set', function () {
    $acc = W::account();
    $a = W::ad($acc);
    $b = W::ad($acc, 'OUTCOME_SALES', [], $a->adSet);
    $alone = W::ad($acc);
    $live = app(AlertData::class)->liveAds($acc->id);
    $g = app(Gates::class);

    expect($g->hasHealthyAlternative($a, $live, []))->toBeTrue()
        ->and($g->hasHealthyAlternative($a, $live, [$b->id]))->toBeFalse()
        ->and($g->hasHealthyAlternative($alone, $live, []))->toBeFalse();
});

it('gives rules a context with families, daily spend and the learning gate', function () {
    $acc = W::account();
    $sales = W::ad($acc);
    $chat = W::ad($acc, 'OUTCOME_ENGAGEMENT');
    W::spendDays($sales, 3, 300);
    W::spend($chat, W::day(-2), 50, ['msg_conversations' => 3]);

    $ctx = RuleContext::for($acc);

    expect($ctx->family($sales))->toBe(Family::SALES)->and($ctx->family($chat))->toBe(Family::MESSAGES)
        ->and($ctx->dailySpend($sales->id))->toBe(300.0)->and($ctx->isLearning($sales->id))->toBeTrue()
        ->and($ctx->day(-1))->toBe('2026-10-05')->and($ctx->fresh()['ok'])->toBeTrue()
        ->and($ctx->breakEven()['floor'])->toBe(2.5)->and($ctx->targets()['cpp_source'])->toBe('none');
});
