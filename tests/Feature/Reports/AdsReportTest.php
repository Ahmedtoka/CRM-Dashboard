<?php

use App\Analytics\AdsReport;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('groups ad conversations by campaign, attributes the customers\' later orders and joins the spend', function () {
    Cache::flush();
    Http::preventStrayRequests();
    $account = ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'driver' => 'live', 'status' => 'connected', 'credentials' => ['access_token' => 'tok']]);
    $from = CarbonImmutable::parse('2026-09-01 00:00:00');
    $to = CarbonImmutable::parse('2026-09-30 23:59:59');

    $sara = Customer::factory()->create();
    $mona = Customer::factory()->create();
    $c1 = Conversation::factory()->create(['channel_account_id' => $account->id, 'platform' => Platform::Facebook, 'customer_id' => $sara->id, 'ad_id' => '1', 'ad_title' => 'Summer video', 'ad_campaign_name' => 'Summer Launch', 'ad_attributed_at' => '2026-09-10 10:00:00']);
    Conversation::factory()->create(['channel_account_id' => $account->id, 'platform' => Platform::Facebook, 'customer_id' => $mona->id, 'ad_id' => '2', 'ad_title' => 'Summer carousel', 'ad_campaign_name' => 'Summer Launch', 'ad_attributed_at' => '2026-09-12 10:00:00']);
    Conversation::factory()->create(['channel_account_id' => $account->id, 'platform' => Platform::Facebook, 'customer_id' => $mona->id, 'ad_id' => '3', 'ad_title' => 'Scarves', 'ad_campaign_name' => null, 'ad_attributed_at' => '2026-09-20 10:00:00']);
    Conversation::factory()->create(['channel_account_id' => $account->id, 'platform' => Platform::Facebook, 'customer_id' => $sara->id]); // no ad

    // Sara ordered after her ad touch (counts), Mona ordered before hers (does not), and Mona again after the scarves ad.
    Order::factory()->create(['customer_id' => $sara->id, 'status' => OrderStatus::Confirmed, 'total' => 1200, 'placed_at' => '2026-09-11 12:00:00']);
    Order::factory()->create(['customer_id' => $mona->id, 'status' => OrderStatus::Confirmed, 'total' => 500, 'placed_at' => '2026-09-05 12:00:00']);
    Order::factory()->create(['customer_id' => $mona->id, 'status' => OrderStatus::Confirmed, 'total' => 800, 'placed_at' => '2026-09-21 12:00:00']);
    Order::factory()->create(['customer_id' => $sara->id, 'status' => OrderStatus::Cancelled, 'total' => 999, 'placed_at' => '2026-09-15 12:00:00']);

    Http::fake([
        'graph.facebook.com/*/me/adaccounts*' => Http::response(['data' => [['id' => 'act_1']]]),
        'graph.facebook.com/*/act_1/insights*' => Http::response(['data' => [
            ['campaign_id' => '9001', 'campaign_name' => 'Summer Launch', 'spend' => '600.50', 'account_currency' => 'EGP'],
            ['campaign_id' => '9002', 'campaign_name' => 'Brand awareness', 'spend' => '200', 'account_currency' => 'EGP'],
        ]]),
    ]);

    $report = app(AdsReport::class)->build($from, $to);
    $rows = collect($report['rows'])->keyBy('campaign');

    expect($rows['Summer Launch']['conversations'])->toBe(2)
        ->and($rows['Summer Launch']['customers'])->toBe(2)
        ->and($rows['Summer Launch']['orders'])->toBe(1)
        ->and($rows['Summer Launch']['revenue'])->toBe(1200.0)
        ->and($rows['Summer Launch']['spend'])->toBe(600.5)
        ->and($rows['Summer Launch']['roas'])->toBe(2.0)
        ->and($rows['Scarves']['orders'])->toBe(1)
        ->and($rows['Scarves']['revenue'])->toBe(800.0)
        ->and($rows['Scarves']['spend'])->toBeNull()
        ->and($rows['Brand awareness']['conversations'])->toBe(0)
        ->and($rows['Brand awareness']['spend'])->toBe(200.0)
        ->and($report['totals']['orders'])->toBe(2)
        ->and($report['totals']['spend'])->toBe(800.5)
        ->and($report['currency'])->toBe('EGP')
        ->and($report['spend_available'])->toBeTrue();

    $supervisor = User::factory()->create(['role' => 'supervisor']);
    $this->actingAs($supervisor)->get('/reports/ads?from=2026-09-01&to=2026-09-30')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Reports/Ads')->has('report.rows', 3));
});

it('says the spend is unavailable when the token cannot read ads', function () {
    Cache::flush();
    Http::preventStrayRequests();
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'driver' => 'live', 'status' => 'connected', 'credentials' => ['access_token' => 'tok']]);
    Http::fake(['graph.facebook.com/*/me/adaccounts*' => Http::response(['error' => ['message' => 'ads_read', 'code' => 200]], 403)]);

    $report = app(AdsReport::class)->build(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));

    expect($report['spend_available'])->toBeFalse()->and($report['rows'])->toBe([]);
});
