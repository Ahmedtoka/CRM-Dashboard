<?php

use App\Ads\Reports\Reconciliation;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountDaily;
use App\Models\AdDailyMetric;
use App\Models\AdsSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config(['crm.ads.history_start' => '2026-09-01']);
    CarbonImmutable::setTestNow('2026-10-05 10:00:00');
    $this->travelTo(now());
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** One account with a September of 30 control days (1,000 in total), 30 ad days (995) and one ok sync covering it. */
/** @param  list<array{0:string,1:string}>  $runs  ok sync windows; default covers the whole history up to yesterday (2026-10-04) */
function rcAccount(string $name = 'LV Main', array $skipControl = [], ?array $runs = null): AdAccount
{
    $a = AdAccount::factory()->meta()->create(['name' => $name]);
    $ad = Ad::factory()->for($a, 'account')->create();
    for ($i = 1; $i <= 30; $i++) {
        $day = sprintf('2026-09-%02d', $i);
        $last = $i === 30;
        if (! in_array($day, $skipControl, true)) {
            AdAccountDaily::create(['ad_account_id' => $a->id, 'date' => $day, 'spend' => $last ? 43 : 33, 'purchase_value' => $last ? 143 : 133, 'purchases' => 1, 'impressions' => 1000, 'fetched_at' => now()]);
        }
        AdDailyMetric::factory()->create(['ad_id' => $ad->id, 'ad_account_id' => $a->id, 'date' => $day, 'spend' => $last ? 38 : 33, 'purchase_value' => $last ? 138 : 133, 'purchases' => 1]);
    }
    foreach ($runs ?? [['2026-09-01', '2026-10-04']] as [$from, $to]) {
        AdsSyncRun::factory()->create(['ad_account_id' => $a->id, 'status' => 'ok', 'kind' => 'backfill', 'from_date' => $from, 'to_date' => $to]);
    }

    return $a;
}

it('adds the complete_from column, guarded and reversible', function () {
    expect(Schema::hasColumn('ad_accounts', 'complete_from'))->toBeTrue();

    $m = require database_path('migrations/2026_10_06_100160_add_complete_from_to_ad_accounts.php');
    $m->up(); // second run is a no-op
    $m->down();
    expect(Schema::hasColumn('ad_accounts', 'complete_from'))->toBeFalse();
    $m->up();
    expect(Schema::hasColumn('ad_accounts', 'complete_from'))->toBeTrue();
});

it('reports coverage and the residual per account and month and stores complete_from', function () {
    $a = rcAccount();

    expect(Artisan::call('ads:reconcile', ['--month' => '2026-09']))->toBe(0);

    $r = app(Reconciliation::class)->account($a, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
    $m = $r['months'][0];
    expect($m['month'])->toBe('2026-09')->and($m['days_expected'])->toBe(30)->and($m['days_with_control'])->toBe(30)->and($m['days_with_ads'])->toBe(30)
        ->and($m['control_spend'])->toBe(1000.0)->and($m['ad_spend'])->toBe(995.0)
        ->and($m['coverage_pct'])->toBe(99.5)->and($m['residual'])->toBe(5.0)
        ->and($m['control_purchase_value'])->toBe(4000.0)->and($m['ad_purchase_value'])->toBe(3995.0)
        ->and($r['complete_from'])->toBe('2026-09-01');
    expect(substr((string) DB::table('ad_accounts')->where('id', $a->id)->value('complete_from'), 0, 10))->toBe('2026-09-01');

    $out = Artisan::output();
    expect($out)->toContain('LV Main')->and($out)->toContain('1,000.00')->and($out)->toContain('99.5')->and($out)->toContain('Ads Manager');
});

it('moves complete_from past a day no ok sync covers and lists the day', function () {
    $a = rcAccount('Gappy', [], [['2026-09-01', '2026-09-03'], ['2026-09-05', '2026-10-04']]);

    Artisan::call('ads:reconcile', ['--month' => '2026-09']);

    $r = app(Reconciliation::class)->account($a, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
    expect($r['complete_from'])->toBe('2026-09-05')->and($r['uncovered_days'])->toBe(['2026-09-04']);
    expect(substr((string) DB::table('ad_accounts')->where('id', $a->id)->value('complete_from'), 0, 10))->toBe('2026-09-05');
    expect(Artisan::output())->toContain('2026-09-04');
});

it('counts an idle day (no control row, an ok sync covers it) as covered with spend 0', function () {
    $a = rcAccount('Idle', ['2026-09-04']);

    Artisan::call('ads:reconcile', ['--month' => '2026-09']);

    $r = app(Reconciliation::class)->account($a, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
    expect($r['complete_from'])->toBe('2026-09-01')->and($r['uncovered_days'])->toBe([])
        ->and($r['months'][0]['missing_control_days'])->toBe(['2026-09-04'])->and($r['months'][0]['days_with_control'])->toBe(29);
    expect(substr((string) DB::table('ad_accounts')->where('id', $a->id)->value('complete_from'), 0, 10))->toBe('2026-09-01');
});

it('does not count a run whose account-level control call failed', function () {
    $a = rcAccount('Cut');
    AdsSyncRun::where('ad_account_id', $a->id)->update(['error' => 'Account totals: Meta said no']);

    expect(app(Reconciliation::class)->account($a, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'))['complete_from'])->toBeNull();
});

it('judges complete_from over history start to yesterday whatever month is shown', function () {
    $a = rcAccount('October gap', [], [['2026-09-01', '2026-10-01'], ['2026-10-03', '2026-10-04']]);

    Artisan::call('ads:reconcile', ['--month' => '2026-09']);
    expect(substr((string) DB::table('ad_accounts')->where('id', $a->id)->value('complete_from'), 0, 10))->toBe('2026-10-03');

    // fix the gap, then show a month before the history start: nothing is nulled or judged on that window
    AdsSyncRun::factory()->create(['ad_account_id' => $a->id, 'status' => 'ok', 'from_date' => '2026-10-02', 'to_date' => '2026-10-02']);
    Artisan::call('ads:reconcile', ['--month' => '2026-10']);
    expect(substr((string) DB::table('ad_accounts')->where('id', $a->id)->value('complete_from'), 0, 10))->toBe('2026-09-01');

    Artisan::call('ads:reconcile', ['--month' => '2026-08']);
    expect(substr((string) DB::table('ad_accounts')->where('id', $a->id)->value('complete_from'), 0, 10))->toBe('2026-09-01');
});

it('rejects a malformed --month', function () {
    rcAccount();

    foreach (['2026-13', '2026-9', 'september', '2026-09-01'] as $bad) {
        expect(Artisan::call('ads:reconcile', ['--month' => $bad]))->toBe(1);
        expect(Artisan::output())->toContain('--month must look like 2026-09');
    }
});

it('treats a day no ok sync covers as incomplete', function () {
    $a = rcAccount();
    AdsSyncRun::where('ad_account_id', $a->id)->update(['from_date' => '2026-09-10']);

    $r = app(Reconciliation::class)->account($a, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));

    expect($r['complete_from'])->toBe('2026-09-10');
});

it('filters by --account (id, external id or name)', function () {
    $one = rcAccount('Alpha Shop');
    rcAccount('Beta Shop');

    Artisan::call('ads:reconcile', ['--month' => '2026-09', '--account' => [(string) $one->id]]);
    $out = Artisan::output();
    expect($out)->toContain('Alpha Shop')->and($out)->not->toContain('Beta Shop');

    Artisan::call('ads:reconcile', ['--month' => '2026-09', '--account' => ['Beta Shop']]);
    expect(Artisan::output())->toContain('Beta Shop')->and(Artisan::output())->not->toContain('Alpha Shop');
});

it('only stores complete_from for the filtered account', function () {
    $one = rcAccount('Alpha Shop');
    $two = rcAccount('Beta Shop');

    Artisan::call('ads:reconcile', ['--month' => '2026-09', '--account' => [(string) $one->id]]);

    expect(DB::table('ad_accounts')->where('id', $one->id)->value('complete_from'))->not->toBeNull()
        ->and(DB::table('ad_accounts')->where('id', $two->id)->value('complete_from'))->toBeNull();
});

it('prints a markdown table with a blank Ads Manager column', function () {
    rcAccount();

    Artisan::call('ads:reconcile', ['--month' => '2026-09', '--markdown' => true]);
    $out = Artisan::output();

    expect($out)->toContain('| Account | Month | Days (control / ads / expected) |')
        ->and($out)->toContain('| Ads Manager |')
        ->and($out)->toMatch('/\| LV Main \| 2026-09 \| 30 \/ 30 \/ 30 \|/');
});

it('checks the gate with the Ads Manager figure the owner types', function () {
    $a = rcAccount();

    Artisan::call('ads:reconcile', ['--month' => '2026-09', '--ads-manager' => ["{$a->id}=1,003.00"]]);
    $out = Artisan::output();
    expect($out)->toContain('[PASS] 1.')->and($out)->toContain('[PASS] 2.')->and($out)->toContain('[PASS] 4.')->and($out)->toContain('0.30');

    Artisan::call('ads:reconcile', ['--month' => '2026-09', '--ads-manager' => ["{$a->id}=1100"]]);
    expect(Artisan::output())->toContain('[FAIL] 1.');

    Artisan::call('ads:reconcile', ['--month' => '2026-09']);
    expect(Artisan::output())->toContain('[TODO] 1.');
});

it('fails the history item when complete_from is later than the history start', function () {
    rcAccount('Gappy', [], [['2026-09-01', '2026-09-03'], ['2026-09-05', '2026-10-04']]);

    Artisan::call('ads:reconcile', ['--month' => '2026-09']);

    expect(Artisan::output())->toContain('[FAIL] 4.');
});

it('writes nothing except complete_from', function () {
    rcAccount();
    $writes = [];
    DB::listen(function ($q) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $q->sql)) {
            $writes[] = $q->sql;
        }
    });

    Artisan::call('ads:reconcile', ['--month' => '2026-09']);

    expect($writes)->toHaveCount(1)->and($writes[0])->toMatch('/^update\s+["`]?ad_accounts["`]?\s+set\s+["`]?complete_from["`]?\s*=/i');
});

it('updates complete_from silently with --quiet-update over the whole history', function () {
    $a = rcAccount('Quiet', [], [['2026-09-01', '2026-10-03']]);

    expect(Artisan::call('ads:reconcile', ['--from' => '2026-09-01', '--quiet-update' => true]))->toBe(0);

    expect(trim(Artisan::output()))->toBe('');
    // yesterday (2026-10-04) is not covered: null, never a date judged on a shorter window
    expect(DB::table('ad_accounts')->where('id', $a->id)->value('complete_from'))->toBeNull();
});

it('is scheduled nightly after the deep sync', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'ads:reconcile'));

    expect($events)->toHaveCount(1)->and($events->first()->command)->toContain('--quiet-update')->and($events->first()->command)->toMatch('/--from=[0-9]{4}-[0-9]{2}-[0-9]{2}/');
});

it('does not count an ok run from before the account-level control existed', function () {
    $a = AdAccount::factory()->meta()->create(['name' => 'Old runs']);
    // a master-era run: ok, no error, but the account has no control rows at all
    AdsSyncRun::factory()->create(['ad_account_id' => $a->id, 'status' => 'ok', 'from_date' => '2026-09-01', 'to_date' => '2026-10-04', 'started_at' => '2026-10-04 02:00:00', 'finished_at' => '2026-10-04 02:10:00']);

    $r = app(Reconciliation::class)->account($a, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
    expect($r['complete_from'])->toBeNull();

    // control starts being fetched on 2026-10-04 12:00: the older run still covers nothing, a later one does
    AdAccountDaily::create(['ad_account_id' => $a->id, 'date' => '2026-10-04', 'spend' => 1, 'purchase_value' => 0, 'purchases' => 0, 'impressions' => 0, 'fetched_at' => '2026-10-04 12:00:00']);
    expect(app(Reconciliation::class)->account($a, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'))['complete_from'])->toBeNull();
    AdsSyncRun::factory()->create(['ad_account_id' => $a->id, 'status' => 'ok', 'from_date' => '2026-09-01', 'to_date' => '2026-10-04', 'started_at' => '2026-10-04 13:00:00', 'finished_at' => '2026-10-04 13:20:00']);
    expect(app(Reconciliation::class)->account($a, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'))['complete_from'])->toBe('2026-09-01');
});

it('counts the run that fetched the first control rows itself', function () {
    $a = AdAccount::factory()->meta()->create(['name' => 'First backfill']);
    // the first backfill chunk starts before it writes the account's first control row and finishes after it
    AdsSyncRun::factory()->create(['ad_account_id' => $a->id, 'status' => 'ok', 'error' => null, 'from_date' => '2026-09-01', 'to_date' => '2026-10-04', 'started_at' => '2026-10-05 01:00:00', 'finished_at' => '2026-10-05 01:30:00']);
    AdAccountDaily::create(['ad_account_id' => $a->id, 'date' => '2026-10-04', 'spend' => 1, 'purchase_value' => 0, 'purchases' => 0, 'impressions' => 0, 'fetched_at' => '2026-10-05 01:10:00']);

    expect(app(Reconciliation::class)->account($a, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'))['complete_from'])->toBe('2026-09-01');
});

it('shows the friendly message for a bad --from', function () {
    rcAccount();

    expect(Artisan::call('ads:reconcile', ['--from' => 'not-a-date']))->toBe(1);
    expect(Artisan::output())->toContain('Use --from and --to as YYYY-MM-DD.');
});
