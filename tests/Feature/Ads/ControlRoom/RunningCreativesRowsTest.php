<?php

require_once __DIR__.'/../../../Support/AdsControlRoom.php';

use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\RunningCreatives;
use App\Models\AdAccount;
use Illuminate\Http\Request;

beforeEach(fn () => crSetup($this));

function rcBuild(array $opts = [], array $q = ['from' => '2026-09-30', 'to' => '2026-10-06']): array
{
    return app(RunningCreatives::class)->build(AdsFilter::fromRequest(Request::create('/ads/explorer', 'GET', $q), crAdmin()), $opts);
}

it('adds objective, chats, real revenue, real ROAS and today spend to each row', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    $msg = crAd($acc, ['2026-10-05' => [200, 0, 0, 12], '2026-10-06' => [50, 0, 0, 3]], [], 'OUTCOME_ENGAGEMENT');
    crOrder($msg, '2026-10-05 18:00', 600);

    $row = collect(rcBuild()['data'])->firstWhere('id', $msg->id);

    expect($row['objective'])->toBe('messages')
        ->and($row['conversations'])->toBe(15)
        ->and($row['real_orders'])->toBe(1)
        ->and($row['real_revenue'])->toBe(600.0)
        ->and($row['real_roas'])->toBe(2.4)
        ->and($row['spend_today'])->toBe(50.0);
});

it('keeps only spenders without any result when no_result_min is set', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    $dead = crAd($acc, ['2026-10-04' => [1500, 0, 0, 0]]);
    crAd($acc, ['2026-10-04' => [1500, 2, 900, 0]]);
    crAd($acc, ['2026-10-04' => [1500, 0, 0, 9]], [], 'MESSAGES');
    crAd($acc, ['2026-10-04' => [100, 0, 0, 0]]);

    expect(collect(rcBuild(['no_result_min' => 1000])['data'])->pluck('id')->all())->toBe([$dead->id]);
});

it('filters by objective family and by an id list, and sorts ascending on request', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    $a = crAd($acc, ['2026-10-04' => [300, 1, 100, 0]]);
    $b = crAd($acc, ['2026-10-04' => [100, 1, 100, 0]]);
    $m = crAd($acc, ['2026-10-04' => [200, 0, 0, 5]], [], 'MESSAGES');

    expect(collect(rcBuild(['objective' => 'sales', 'sort' => 'spend', 'dir' => 'asc'])['data'])->pluck('id')->all())->toBe([$b->id, $a->id])
        ->and(collect(rcBuild(['objective' => 'messages'])['data'])->pluck('id')->all())->toBe([$m->id])
        ->and(collect(rcBuild(['only_ids' => [$a->id]])['data'])->pluck('id')->all())->toBe([$a->id])
        ->and(rcBuild(['only_ids' => []])['data'])->toBe([]);
});
