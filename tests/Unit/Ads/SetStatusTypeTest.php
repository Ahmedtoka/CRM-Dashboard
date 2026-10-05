<?php

use App\Ads\Control\Write\Canonical;
use App\Ads\Control\Write\Types\SetStatusType;
use App\Ads\Control\Write\WriteDenied;
use App\Ads\Platforms\Data\ObjectState;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdCampaign;
use App\Models\AdSet;
use App\Models\AdWriteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function sstLive(string $status = 'PAUSED', ?int $parentDaily = 150000, ?int $ownDaily = null, ?int $lifetime = null): ObjectState
{
    return new ObjectState($status, $status, $ownDaily, null, null, 'EGP', [
        ['level' => 'adset', 'status' => 'ACTIVE', 'dailyBudgetMinor' => $parentDaily, 'lifetimeBudgetMinor' => $lifetime, 'endsAt' => null],
        ['level' => 'campaign', 'status' => 'ACTIVE', 'dailyBudgetMinor' => null, 'lifetimeBudgetMinor' => null, 'endsAt' => null],
    ]);
}

it('produces the same canonical json and hash whatever the key order', function () {
    $a = ['b' => 1, 'a' => ['y' => 'ص', 'x' => [3, 1, 2]], 'url' => 'https://x.test/a'];
    $b = ['url' => 'https://x.test/a', 'a' => ['x' => [3, 1, 2], 'y' => 'ص'], 'b' => 1];

    expect(Canonical::json($a))->toBe(Canonical::json($b))
        ->and(Canonical::json($a))->toBe('{"a":{"x":[3,1,2],"y":"ص"},"b":1,"url":"https://x.test/a"}')
        ->and(Canonical::hash($a))->toBe(Canonical::hash($b))->toHaveLength(64)
        ->and(Canonical::hash(['x' => [1, 2]]))->not->toBe(Canonical::hash(['x' => [2, 1]])); // list order matters
});

it('finds the target in the account at each level and refuses an unknown one with 404', function () {
    $acc = AdAccount::factory()->meta()->create();
    $camp = AdCampaign::factory()->for($acc, 'account')->create();
    $set = AdSet::factory()->for($camp, 'campaign')->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $other = AdAccount::factory()->meta()->create();
    $type = app(SetStatusType::class);

    expect($type->target($acc, 'campaign', $camp->external_id)->is($camp))->toBeTrue()
        ->and($type->target($acc, 'adset', $set->external_id)->is($set))->toBeTrue()
        ->and($type->target($acc, 'ad', $ad->external_id)->is($ad))->toBeTrue();

    try {
        $type->target($other, 'ad', $ad->external_id);
        $this->fail('expected not_found');
    } catch (WriteDenied $e) {
        expect($e->status)->toBe(404)->and($e->errorCode)->toBe('not_found');
    }
});

it('builds a Stop with only the status row, the target key and the local status as before', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'ACTIVE', 'name' => 'إعلان العباية']);

    $b = app(SetStatusType::class)->build($acc, $ad, 'paused', null, [], []);

    expect($b['params'])->toBe(['to' => 'paused'])
        ->and($b['diff'])->toBe([['path' => 'status', 'before' => 'ACTIVE', 'after' => 'PAUSED']])
        ->and($b['expected'])->toBe(['status' => 'ACTIVE', 'read_at' => null])
        ->and($b['target_key'])->toBe($acc->id.':ad:'.$ad->external_id)
        ->and($b['from_status'])->toBe('ACTIVE')
        ->and(SetStatusType::runKey($b['target_key']))->toBe('run:'.$acc->id.':ad:'.$ad->external_id)
        ->and($b['diff_hash'])->toBe(Canonical::hash([
            'type' => 'set_status', 'account_id' => $acc->id, 'level' => 'ad', 'external_id' => $ad->external_id,
            'params' => ['to' => 'paused'], 'diff' => $b['diff'],
        ]));
});

it('builds a Run with a live read: live status first and budget rows in minor units with the currency', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'ACTIVE']); // local is stale

    $b = app(SetStatusType::class)->build($acc, $ad, 'active', sstLive('PAUSED', 150000, null, 900000), ['budget' => 'ok'], ['learning']);

    expect($b['diff'][0])->toBe(['path' => 'status', 'before' => 'PAUSED', 'after' => 'ACTIVE'])
        ->and($b['diff'])->toContain(['path' => 'parent_daily_budget', 'level' => 'adset', 'before' => ['minor' => 150000, 'currency' => 'EGP'], 'after' => ['minor' => 150000, 'currency' => 'EGP']])
        ->and($b['diff'])->toContain(['path' => 'lifetime_budget', 'level' => 'adset', 'before' => ['minor' => 900000, 'currency' => 'EGP'], 'after' => ['minor' => 900000, 'currency' => 'EGP']])
        ->and($b['expected']['status'])->toBe('PAUSED')->and($b['expected']['read_at'])->not->toBeNull()
        ->and($b['from_status'])->toBe('PAUSED')
        ->and($b['limits_checked'])->toBe(['budget' => 'ok'])->and($b['notes'])->toBe(['learning']);
});

it('puts the own daily budget of an ad set in the Run diff', function () {
    $acc = AdAccount::factory()->meta()->create();
    $camp = AdCampaign::factory()->for($acc, 'account')->create();
    $set = AdSet::factory()->for($camp, 'campaign')->create(['status' => 'PAUSED']);
    $live = new ObjectState('PAUSED', 'PAUSED', 70000, null, null, 'EGP', [['level' => 'campaign', 'status' => 'ACTIVE', 'dailyBudgetMinor' => null, 'lifetimeBudgetMinor' => null, 'endsAt' => null]]);

    $b = app(SetStatusType::class)->build($acc, $set, 'active', $live, [], []);

    expect($b['diff'])->toContain(['path' => 'daily_budget', 'level' => 'adset', 'before' => ['minor' => 70000, 'currency' => 'EGP'], 'after' => ['minor' => 70000, 'currency' => 'EGP']])
        ->and($b['target_key'])->toBe($acc->id.':adset:'.$set->external_id);
});

it('changes the hash when the target status, the target or a budget row changes', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $ad2 = Ad::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $type = app(SetStatusType::class);

    $run = $type->build($acc, $ad, 'active', sstLive('PAUSED', 150000), [], [])['diff_hash'];

    expect($type->build($acc, $ad, 'active', sstLive('PAUSED', 150000), [], [])['diff_hash'])->toBe($run)
        ->and($type->build($acc, $ad, 'paused', sstLive('PAUSED', 150000), [], [])['diff_hash'])->not->toBe($run)
        ->and($type->build($acc, $ad2, 'active', sstLive('PAUSED', 150000), [], [])['diff_hash'])->not->toBe($run)
        ->and($type->build($acc, $ad, 'active', sstLive('PAUSED', 160000), [], [])['diff_hash'])->not->toBe($run);
});

it('keeps the hash stable for Arabic names between runs', function () {
    $acc = AdAccount::factory()->meta()->create(['name' => 'لو فوال']);
    $ad = Ad::factory()->for($acc, 'account')->create(['name' => 'عباية سوداء - ريلز']);
    $type = app(SetStatusType::class);

    expect($type->build($acc, $ad, 'paused', null, [], [])['diff_hash'])->toBe($type->build($acc->fresh(), $ad->fresh(), 'paused', null, [], [])['diff_hash']);
});

it('inverts a succeeded Stop into a Run and a succeeded Run into a Stop, nothing else', function () {
    $type = app(SetStatusType::class);

    expect($type->inverse(AdWriteAction::factory()->stop()->succeeded()->make()))->toBe(['to' => 'active'])
        ->and($type->inverse(AdWriteAction::factory()->run()->succeeded()->make()))->toBe(['to' => 'paused'])
        ->and($type->inverse(AdWriteAction::factory()->stop()->make(['state' => AdWriteAction::FAILED])))->toBeNull()
        ->and($type->inverse(AdWriteAction::factory()->run()->unknown()->make()))->toBeNull()
        ->and($type->inverse(AdWriteAction::factory()->stop()->proposed()->make()))->toBeNull();
});
