<?php

use App\Ads\Alerts\RuleSettings;
use App\Ads\Alerts\Targets;
use Tests\Support\AlertWorld as W;

beforeEach(fn () => W::freeze());

it('uses the owner targets when they are set', function () {
    $acc = W::account();
    app(RuleSettings::class)->saveInputs(W::authority(), $acc->id, ['target_cpp' => 320, 'target_cpo' => 280]);

    expect(app(Targets::class)->forAccount($acc->id, W::today()))
        ->toMatchArray(['cpp' => 320.0, 'cpp_source' => 'owner', 'cpo' => 280.0, 'cpo_source' => 'owner']);
});

it('takes the 60-day median cost per purchase over at least three ads, best of Meta and real orders', function () {
    $acc = W::account();
    foreach ([[300, 3], [600, 3], [900, 3]] as [$spend, $purchases]) {
        W::spend(W::ad($acc), W::day(-5), $spend, ['purchases' => $purchases]);
    }
    $crm = W::ad($acc);
    W::spend($crm, W::day(-5), 1200, ['purchases' => 1]);
    foreach (range(1, 4) as $i) {
        W::order($crm, W::day(-5).' 12:00', 900);
    }

    $t = app(Targets::class)->forAccount($acc->id, W::today());

    // CPAs 100, 200, 300 and 1,200 / max(1, 4 real orders) = 300 -> median 250
    expect($t['cpp'])->toBe(250.0)->and($t['cpp_source'])->toBe('median')->and($t['cpo'])->toBeNull()->and($t['cpo_source'])->toBe('none');
});

it('has no median with fewer than three ads', function () {
    $acc = W::account();
    W::spend(W::ad($acc), W::day(-5), 300, ['purchases' => 3]);
    W::spend(W::ad($acc), W::day(-5), 600, ['purchases' => 3]);

    expect(app(Targets::class)->forAccount($acc->id, W::today()))->toMatchArray(['cpp' => null, 'cpp_source' => 'none']);
});

it('medians the cost per chat order and per conversation from the chat signals', function () {
    $acc = W::account();
    $rows = [];
    foreach ([[400, 2, 40], [600, 2, 30], [900, 3, 30]] as [$spend, $orders, $conv]) {
        $ad = W::ad($acc, 'MESSAGES');
        W::spend($ad, W::day(-5), $spend, ['msg_conversations' => $conv]);
        $rows[$ad->id] = ['chats' => $conv, 'orders' => $orders];
    }
    W::fakeChats($rows);

    $t = app(Targets::class)->forAccount($acc->id, W::today());

    // CPO 200, 300, 300 -> 300; cost per conversation 10, 20, 30 -> 20
    expect($t['cpo'])->toBe(300.0)->and($t['cpo_source'])->toBe('median')->and($t['cpc'])->toBe(20.0);
});
