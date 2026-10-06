<?php

use App\Ads\Alerts\ChatSignals;
use Tests\Support\AlertWorld as W;

beforeEach(fn () => W::freeze());

it('has a zero row shape and answers nothing for no ads', function () {
    expect(ChatSignals::empty())->toBe(['chats' => 0, 'to_agent' => 0, 'orders' => 0, 'delivered' => 0, 'returned' => 0, 'reasons' => []])
        ->and(app(ChatSignals::class)->forAds([], W::day(-14), W::day(-1)))->toBe([]);
});

it('delegates to the S3 chat funnel', function () {
    $ad = W::ad(W::account(), 'MESSAGES');

    $rows = app(ChatSignals::class)->forAds([$ad->id], W::day(-14), W::day(-1));

    expect($rows[$ad->id]['chats'] ?? 0)->toBe(0);
});
