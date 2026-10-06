<?php

use App\Ads\Sync\AdsSyncService;
use App\Ads\Sync\HistoryWindow;
use App\Ads\Sync\SyncAdAccount;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdDailyMetric;
use App\Models\AdPlatformConnection;
use App\Models\AdsSyncRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Africa/Cairo'));
    config(['crm.ads.drivers.meta' => 'live', 'crm.ads.history_start' => '2026-09-01']);
    Http::preventStrayRequests();
});

function hwAccount(): AdAccount
{
    $c = AdPlatformConnection::factory()->meta()->create(['credentials' => ['access_token' => 'tok']]);

    return AdAccount::factory()->meta()->create(['external_id' => 'act_1', 'connection_id' => $c->id]);
}

function hwFake(array $insights = []): void
{
    Http::fake([
        'graph.facebook.com/*/act_1/ads*' => Http::response(['data' => []]),
        'graph.facebook.com/*/act_1/insights*' => Http::response(['data' => $insights]),
        'graph.facebook.com/*/act_1/campaigns*' => Http::response(['data' => []]), // status sweep of backfill chunk 0 (A1c)
    ]);
}

function hwSinces(): array
{
    return collect(Http::recorded())
        ->map(fn ($p) => $p[0]->url())
        ->filter(fn ($u) => str_contains($u, '/insights'))
        ->map(function ($u) {
            parse_str((string) parse_url($u, PHP_URL_QUERY), $q);

            return $q;
        })
        ->filter(fn ($q) => ($q['level'] ?? null) === 'ad') // the account-level control (A2) repeats the same range
        ->map(fn ($q) => json_decode($q['time_range'], true))->values()->all();
}

it('chunks a 90-day backfill only inside the history window', function () {
    hwFake();
    $a = hwAccount();

    app(AdsSyncService::class)->backfill($a, 90);

    $ranges = hwSinces();
    expect($ranges)->toHaveCount(2)
        ->and($ranges[0])->toBe(['since' => '2026-09-06', 'until' => '2026-10-05'])
        ->and($ranges[1])->toBe(['since' => '2026-09-01', 'until' => '2026-09-05']);
    foreach ($ranges as $r) {
        expect($r['since'] >= '2026-09-01')->toBeTrue();
    }
});

it('skips a window entirely before the start without calling the platform', function () {
    hwFake();
    $a = hwAccount();

    $run = app(AdsSyncService::class)->syncAccount($a, CarbonImmutable::parse('2026-08-20'), CarbonImmutable::parse('2026-08-31'));

    expect(collect(Http::recorded()))->toHaveCount(0)
        ->and($run->status)->toBe('skipped')
        ->and($run->error)->toBe('Window before history start')
        ->and(AdsSyncRun::count())->toBe(1);
});

it('clamps a window that starts before the history start', function () {
    hwFake();
    $a = hwAccount();

    $ad = Ad::factory()->create(['ad_account_id' => $a->id, 'external_id' => 'ad_old', 'status' => 'unknown']);
    AdDailyMetric::factory()->create(['ad_id' => $ad->id, 'ad_account_id' => $a->id, 'date' => '2026-08-31', 'spend' => 3]);

    app(AdsSyncService::class)->syncAccount($a, CarbonImmutable::parse('2026-08-28'), CarbonImmutable::parse('2026-09-03'));

    expect(hwSinces())->toBe([['since' => '2026-09-01', 'until' => '2026-09-03']])
        ->and(AdDailyMetric::where('ad_account_id', $a->id)->where('date', '2026-08-31')->exists())->toBeTrue();
});

it('never stores payload rows before the start and never deletes stored rows before it', function () {
    hwFake([
        ['ad_id' => 'ad_1', 'ad_name' => 'A', 'date_start' => '2026-08-31', 'spend' => '5', 'impressions' => '10', 'clicks' => '1', 'reach' => '9'],
        ['ad_id' => 'ad_1', 'ad_name' => 'A', 'date_start' => '2026-09-02', 'spend' => '7', 'impressions' => '10', 'clicks' => '1', 'reach' => '9'],
    ]);
    $a = hwAccount();
    $ad = Ad::factory()->create(['ad_account_id' => $a->id, 'external_id' => 'ad_old', 'status' => 'unknown']);
    AdDailyMetric::factory()->create(['ad_id' => $ad->id, 'ad_account_id' => $a->id, 'date' => '2026-08-31', 'spend' => 3]);

    app(AdsSyncService::class)->syncAccount($a, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-03'));

    $dates = AdDailyMetric::where('ad_account_id', $a->id)->orderBy('date')->get()->map(fn ($m) => $m->date->toDateString())->all();
    expect($dates)->toBe(['2026-08-31', '2026-09-02'])
        ->and((float) AdDailyMetric::where('ad_account_id', $a->id)->where('date', '2026-08-31')->value('spend'))->toBe(3.0);
});

it('clamps ads:backfill --from and warns', function () {
    hwFake();
    $a = hwAccount();

    $this->artisan('ads:backfill', ['--from' => '2026-08-01', '--account' => $a->id])
        ->expectsOutputToContain('clamped')
        ->assertSuccessful();

    $ranges = hwSinces();
    expect(collect($ranges)->min('since'))->toBe('2026-09-01');
});

it('clamps the report filter from date', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->get('/ads?from=2026-07-01&to=2026-09-10')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('filters.from', '2026-09-01'));
});

it('reads the start from config, not a constant', function () {
    config(['crm.ads.history_start' => '2026-09-15']);

    expect(HistoryWindow::start()->toDateString())->toBe('2026-09-15')
        ->and(HistoryWindow::daysFromStart(CarbonImmutable::parse('2026-10-05', 'Africa/Cairo')))->toBe(21);
});

it('defaults the history start to the data floor (F3)', function () {
    $src = file_get_contents(config_path('crm.php'));

    expect($src)->toContain("'history_start' => env('CRM_ADS_HISTORY_START')")
        ->and($src)->toContain("'data_floor' => env('CRM_DATA_FLOOR', '2026-10-01')");
});

it('records a skipped run when a queued sync window is before the start', function () {
    config(['crm.ads.history_start' => '2026-12-01']);
    hwFake();
    $a = hwAccount();

    (new SyncAdAccount($a->id, 3))->handle(app(AdsSyncService::class));

    $run = AdsSyncRun::where('ad_account_id', $a->id)->first();
    expect(collect(Http::recorded()))->toHaveCount(0)
        ->and($run->status)->toBe('skipped')
        ->and($run->error)->toBe('Window before history start');
});
