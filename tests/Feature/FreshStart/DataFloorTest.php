<?php

use App\Ads\Sync\HistoryWindow;
use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/*
 * F3: nothing before crm.data_floor (default 2026-10-01) is ever requested from an ads platform.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Africa/Cairo'));
    config(['crm.ads.drivers.meta' => 'live', 'crm.data_floor' => '2026-10-01', 'crm.ads.history_start' => '2026-09-01']);
    Http::preventStrayRequests();
});

function dfAdsFake(): void
{
    Http::fake([
        'graph.facebook.com/*/act_1/ads*' => Http::response(['data' => []]),
        'graph.facebook.com/*/act_1/insights*' => Http::response(['data' => []]),
        'graph.facebook.com/*/act_1/campaigns*' => Http::response(['data' => []]),
        'graph.facebook.com/*/me/adaccounts*' => Http::response(['data' => [['id' => 'act_1', 'name' => 'A', 'currency' => 'EGP', 'timezone_name' => 'Africa/Cairo', 'account_status' => 1]]]),
    ]);
}

function dfAdsAccount(): AdAccount
{
    $c = AdPlatformConnection::factory()->meta()->create(['credentials' => ['access_token' => 'tok']]);

    return AdAccount::factory()->meta()->create(['external_id' => 'act_1', 'connection_id' => $c->id]);
}

/** Every `since` the ads platform was asked for. */
function dfSinces(): array
{
    return collect(Http::recorded())
        ->map(fn ($p) => $p[0]->url())
        ->filter(fn ($u) => str_contains($u, '/insights'))
        ->map(function ($u) {
            parse_str((string) parse_url($u, PHP_URL_QUERY), $q);

            return json_decode($q['time_range'] ?? 'null', true)['since'] ?? null;
        })->filter()->values()->all();
}

it('ships 2026-10-01 as the default data floor, overridable by CRM_DATA_FLOOR', function () {
    $src = file_get_contents(config_path('crm.php'));

    expect($src)->toContain("'data_floor' => env('CRM_DATA_FLOOR', '2026-10-01')")
        ->and(file_get_contents(base_path('.env.example')))->toContain('CRM_DATA_FLOOR=2026-10-01');
});

it('uses the data floor as the ads history start when it is later', function () {
    expect(HistoryWindow::start()->toDateString())->toBe('2026-10-01');

    config(['crm.ads.history_start' => '2026-10-05']);
    expect(HistoryWindow::start()->toDateString())->toBe('2026-10-05');

    config(['crm.ads.history_start' => null]);
    expect(HistoryWindow::start()->toDateString())->toBe('2026-10-01');
});

it('clamps ads:backfill --from to the data floor', function () {
    dfAdsFake();
    $a = dfAdsAccount();

    $this->artisan('ads:backfill', ['--from' => '2026-09-01', '--account' => $a->id])
        ->expectsOutputToContain('clamped to 2026-10-01')
        ->assertSuccessful();

    expect(dfSinces())->not->toBeEmpty()
        ->and(collect(dfSinces())->min())->toBe('2026-10-01');
});

it('clamps ads:backfill --days to the data floor', function () {
    dfAdsFake();
    $a = dfAdsAccount();

    $this->artisan('ads:backfill', ['--days' => 90, '--account' => $a->id])->assertSuccessful();

    expect(collect(dfSinces())->min())->toBe('2026-10-01');
});

it('clamps ads:sync windows to the data floor', function () {
    dfAdsFake();
    $a = dfAdsAccount();

    $this->artisan('ads:sync', ['--days' => 60, '--account' => $a->id, '--now' => true]);

    expect(dfSinces())->not->toBeEmpty()
        ->and(collect(dfSinces())->min())->toBe('2026-10-01');
});
