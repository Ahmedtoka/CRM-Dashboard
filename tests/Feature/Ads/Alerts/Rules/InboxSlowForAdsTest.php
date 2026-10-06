<?php

use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\Rules\InboxSlowForAds;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Tests\Support\AlertWorld as W;

beforeEach(fn () => W::freeze());

/** @param  list<?int>  $replyAfterMinutes  one referral per entry yesterday at 12:00; null = never answered */
function isWorld(array $replyAfterMinutes)
{
    $acc = W::account(['name' => 'LV-Main 2']);
    $ad = W::ad($acc, 'MESSAGES');
    W::spend($ad, W::day(-1), 500);
    foreach ($replyAfterMinutes as $minutes) {
        $r = W::referral($ad, W::day(-1).' 12:00');
        if ($minutes !== null) {
            Message::factory()->create(['conversation_id' => $r->conversation_id, 'direction' => 'out', 'sender_type' => 'user',
                'created_at' => CarbonImmutable::parse(W::day(-1).' 12:00', 'Africa/Cairo')->addMinutes($minutes)->utc()]);
        }
    }

    return $acc;
}

it('tells the owner ad chats waited too long for a first reply', function () {
    $acc = isWorld([...array_fill(0, 8, 5), ...array_fill(0, 12, 30)]);

    $f = app(InboxSlowForAds::class)->evaluate(RuleContext::for($acc));

    expect($f)->toHaveCount(1)->and($f[0]->entityLevel)->toBe('account')->and($f[0]->severity)->toBe('high')->and($f[0]->action)->toBe('open_queue')
        ->and($f[0]->params)->toBe(['account' => 'LV-Main 2', 'share' => 0, 'minutes' => 30, 'chats' => 20]);
});

it('fires on unanswered chats and prices the waste', function () {
    $acc = isWorld([...array_fill(0, 14, 2), ...array_fill(0, 6, null)]);

    $f = app(InboxSlowForAds::class)->evaluate(RuleContext::for($acc));

    expect($f[0]->params['share'])->toBe(30)->and($f[0]->moneyAtRiskPerDay)->toBe(150.0);
});

it('stays quiet for a fast inbox or a small sample', function (array $waits) {
    expect(app(InboxSlowForAds::class)->evaluate(RuleContext::for(isWorld($waits))))->toBe([]);
})->with([[array_fill(0, 20, 2)], [array_fill(0, 19, 45)]]);
