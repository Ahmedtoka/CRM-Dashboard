<?php

require_once __DIR__.'/../../../Support/AdsControlRoom.php';

use App\Ads\Platforms\Data\AccountDailyTotal;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\SpendByHour;
use App\Ads\Reports\SpendSnapshots;
use App\Models\AdAccount;
use App\Models\AdSpendSnapshot;
use Illuminate\Http\Request;

beforeEach(fn () => crSetup($this));

function sbhToday(): array
{
    return app(SpendByHour::class)->today(AdsFilter::fromRequest(Request::create('/ads'), crAdmin(), 'last7'));
}

it('records today control spend at the current Cairo hour, latest value wins', function () {
    $acc = AdAccount::factory()->meta()->create();
    app(SpendSnapshots::class)->record($acc, [new AccountDailyTotal('2026-10-05', 900, 1, 0, 0), new AccountDailyTotal('2026-10-06', 300, 1, 0, 0)]);
    app(SpendSnapshots::class)->record($acc, [new AccountDailyTotal('2026-10-06', 340, 1, 0, 0)]);

    expect(AdSpendSnapshot::count())->toBe(1)
        ->and(AdSpendSnapshot::first()->only(['hour']))->toBe(['hour' => 15])
        ->and((float) AdSpendSnapshot::first()->spend)->toBe(340.0);
});

it('compares today so far with the median of the same hour on earlier days', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    foreach (['2026-10-01' => 100, '2026-10-02' => 200, '2026-10-03' => 300, '2026-10-04' => 400] as $date => $spend) {
        AdSpendSnapshot::create(['ad_account_id' => $acc->id, 'date' => $date, 'hour' => 14, 'spend' => $spend, 'captured_at' => now()]);
        AdSpendSnapshot::create(['ad_account_id' => $acc->id, 'date' => $date, 'hour' => 20, 'spend' => $spend * 3, 'captured_at' => now()]);
    }
    crAd($acc, ['2026-10-06' => [500, 0, 0, 0]]);
    AdSpendSnapshot::create(['ad_account_id' => $acc->id, 'date' => '2026-10-06', 'hour' => 15, 'spend' => 500, 'captured_at' => now()]);

    $r = sbhToday();

    expect($r['baseline'])->toBe('snapshots')
        ->and($r['hour'])->toBe(15)
        ->and($r['spend_so_far'])->toBe(500.0)
        ->and($r['usual_by_now'])->toBe(250.0)   // median of 100,200,300,400 at hour <= 15
        ->and($r['ratio'])->toBe(2.0)
        ->and($r['hours'])->toHaveCount(16);
});

it('falls back to the last 7 complete days pro-rated by the time of day', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    crAd($acc, ['2026-10-02' => [700, 0, 0, 0], '2026-10-05' => [700, 0, 0, 0]]);

    $r = sbhToday();

    // 1,400 over 7 days = 200 a day; 15:30 = 930 of 1,440 minutes.
    expect($r['baseline'])->toBe('prorated')->and($r['usual_by_now'])->toBe(round(200 * 930 / 1440, 2));
});

it('says none when there is no spend history at all', function () {
    AdAccount::factory()->meta()->create();
    expect(sbhToday()['baseline'])->toBe('none')->and(sbhToday()['usual_by_now'])->toBeNull();
});

it('keeps 30 days of snapshots: record and ads:prune-history drop older rows (review M1)', function () {
    $acc = AdAccount::factory()->meta()->create();
    AdSpendSnapshot::create(['ad_account_id' => $acc->id, 'date' => '2026-09-05', 'hour' => 10, 'spend' => 1, 'captured_at' => now()]);
    AdSpendSnapshot::create(['ad_account_id' => $acc->id, 'date' => '2026-09-06', 'hour' => 10, 'spend' => 1, 'captured_at' => now()]);

    app(SpendSnapshots::class)->record($acc, [new AccountDailyTotal('2026-10-06', 10, 1, 0, 0)]);
    expect(AdSpendSnapshot::orderBy('date')->pluck('date')->map(fn ($d) => $d->toDateString())->all())->toBe(['2026-09-06', '2026-10-06']);

    AdSpendSnapshot::create(['ad_account_id' => $acc->id, 'date' => '2026-08-01', 'hour' => 9, 'spend' => 1, 'captured_at' => now()]);
    $this->artisan('ads:prune-history', ['--force' => true, '--before' => '2026-09-01'])->assertSuccessful();
    expect(AdSpendSnapshot::where('date', '<', '2026-09-06')->count())->toBe(0)->and(AdSpendSnapshot::count())->toBe(2);
});
