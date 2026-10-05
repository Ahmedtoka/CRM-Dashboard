<?php

use App\Enums\OrderStatus;
use App\Models\Ad;
use App\Models\AdCampaign;
use App\Models\AdsAuditLog;
use App\Models\Customer;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A4 (e-f, R-18, R-19): `ads:attribute-orders --force` snapshots the window's attribution columns first;
 * `ads:attribution-restore` puts them back (dry run by default); without --force, campaign-level utm orders whose
 * utm_content now names a stored ad are upgraded.
 */
beforeEach(fn () => CarbonImmutable::setTestNow('2026-10-05 12:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

function abOrder(array $attrs): Order
{
    return Order::factory()->create(array_merge([
        'customer_id' => Customer::factory()->create()->id,
        'status' => OrderStatus::Confirmed,
        'placed_at' => '2026-09-25 10:00:00',
    ], $attrs));
}

function abBackupTables(): array
{
    return collect(Schema::getTableListing())->map(fn ($t) => str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t)
        ->filter(fn ($t) => str_starts_with($t, 'orders_ad_attr_backup_'))->values()->all();
}

it('snapshots the window before a forced re-attribution and restores it exactly', function () {
    $old = Ad::factory()->create(['external_id' => 'OLD']);
    $new = Ad::factory()->create(['external_id' => 'NEW']);
    $a = abOrder(['utm_content' => 'NEW', 'ad_id' => $old->id, 'ad_campaign_id' => $old->ad_campaign_id, 'ad_attribution' => 'inbox']);
    $b = abOrder(['utm_content' => 'NEW', 'ad_id' => $new->id, 'ad_campaign_id' => $new->ad_campaign_id, 'ad_attribution' => 'utm_ad']);
    $c = abOrder(['ad_id' => $old->id, 'ad_attribution' => 'inbox']); // evidence gone: cleared by --force
    $outside = abOrder(['placed_at' => '2026-08-01 10:00:00', 'utm_content' => 'NEW', 'ad_id' => $old->id, 'ad_attribution' => 'inbox']);
    $before = Order::query()->orderBy('id')->get()->map->only(['id', 'ad_id', 'ad_campaign_id', 'ad_attribution', 'total', 'status', 'updated_at'])->all();

    $this->artisan('ads:attribute-orders', ['--force' => true, '--days' => 35])
        ->expectsOutputToContain('orders_ad_attr_backup_20261005120000')
        ->assertSuccessful();

    expect(abBackupTables())->toBe(['orders_ad_attr_backup_20261005120000'])
        ->and(DB::table('orders_ad_attr_backup_20261005120000')->count())->toBe(3) // a, b, c: the 35-day window
        ->and(DB::table('orders_ad_attr_backup_20261005120000')->where('id', $a->id)->first())->toMatchArray(['ad_id' => $old->id, 'ad_attribution' => 'inbox'])
        ->and($a->fresh()->ad_id)->toBe($new->id)->and($c->fresh()->ad_attribution)->toBeNull()
        ->and($outside->fresh()->ad_id)->toBe($old->id)
        ->and(AdsAuditLog::where('action', 'orders.attribution_backup_created')->sole()->meta)->toMatchArray(['table' => 'orders_ad_attr_backup_20261005120000', 'rows' => 3]);

    // dry run: reports the differences, changes nothing
    $this->artisan('ads:attribution-restore', ['table' => 'orders_ad_attr_backup_20261005120000'])
        ->expectsOutputToContain('2 order(s) differ')
        ->assertSuccessful();
    expect($a->fresh()->ad_id)->toBe($new->id)->and($c->fresh()->ad_attribution)->toBeNull();

    // force: exactly the snapshot again, no other column touched
    $this->artisan('ads:attribution-restore', ['table' => 'orders_ad_attr_backup_20261005120000', '--force' => true])->assertSuccessful();
    $after = Order::query()->orderBy('id')->get()->map->only(['id', 'ad_id', 'ad_campaign_id', 'ad_attribution', 'total', 'status', 'updated_at'])->all();
    expect($after)->toEqual($before)
        ->and(AdsAuditLog::where('action', 'orders.attribution_restored')->sole()->meta)->toMatchArray(['table' => 'orders_ad_attr_backup_20261005120000', 'restored' => 2]);
});

it('takes no snapshot without --force', function () {
    abOrder(['utm_content' => 'X']);

    $this->artisan('ads:attribute-orders', ['--days' => 35])->assertSuccessful();

    expect(abBackupTables())->toBe([]);
});

it('refuses to restore from a table that is not an attribution backup', function () {
    $this->artisan('ads:attribution-restore', ['table' => 'orders', '--force' => true])->assertFailed();
    $this->artisan('ads:attribution-restore', ['table' => 'orders_ad_attr_backup_20990101000000'])->assertFailed();
});

it('upgrades a utm_campaign order to utm_content attribution without --force, inside 35 days only', function () {
    $camp = AdCampaign::factory()->create(['external_id' => 'C1', 'name' => 'Summer']);
    $recent = abOrder(['utm_campaign' => 'Summer', 'utm_content' => 'AD-LATE', 'ad_campaign_id' => $camp->id, 'ad_attribution' => 'utm_campaign']);
    $old = abOrder(['placed_at' => '2026-08-25 10:00:00', 'utm_campaign' => 'Summer', 'utm_content' => 'AD-LATE', 'ad_campaign_id' => $camp->id, 'ad_attribution' => 'utm_campaign']);
    $noContent = abOrder(['utm_campaign' => 'Summer', 'ad_campaign_id' => $camp->id, 'ad_attribution' => 'utm_campaign']);

    // the ad is synced after the orders were attributed at campaign level
    $ad = Ad::factory()->create(['external_id' => 'AD-LATE', 'ad_campaign_id' => $camp->id]);

    $this->artisan('ads:attribute-orders', ['--days' => 60])->assertSuccessful();

    expect($recent->fresh()->ad_id)->toBe($ad->id)->and($recent->fresh()->ad_attribution)->toBe('utm_ad')
        ->and($old->fresh()->ad_id)->toBeNull()->and($old->fresh()->ad_attribution)->toBe('utm_campaign')
        ->and($noContent->fresh()->ad_attribution)->toBe('utm_campaign')
        ->and(abBackupTables())->toBe([]);
});
