<?php

require_once __DIR__.'/../../../Support/AdsControlRoom.php';

use App\Models\AdAccount;
use App\Models\AdWriteAction;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => crSetup($this));

it('lists running ads of the last 7 complete days by spend, with row extras', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    $big = crAd($acc, ['2026-10-04' => [900, 1, 100, 0]]);
    $small = crAd($acc, ['2026-10-04' => [100, 1, 100, 0]]);
    crAd($acc, ['2026-10-04' => [500, 1, 100, 0]], ['effective_status' => 'PAUSED', 'status' => 'PAUSED']);

    $this->actingAs(crAdmin())->get('/ads/explorer')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Ads/Explorer')
        ->where('filters.view', 'table')->where('filters.status', 'running')->where('filters.sort', '-spend')->where('filters.range', 'last7')
        ->where('tree', null)
        ->has('result.data', 2)->where('result.data.0.id', $big->id)->where('result.data.1.id', $small->id)
        ->has('result.data.0.series', 14)->has('result.data.0.health')->has('result.data.0.real_roas')->has('result.data.0.spend_today'));
});

it('answers the spending-without-result preset', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    $dead = crAd($acc, ['2026-10-04' => [1500, 0, 0, 0]]);
    crAd($acc, ['2026-10-04' => [1500, 0, 0, 7]], [], 'MESSAGES');

    $this->actingAs(crAdmin())->get('/ads/explorer?status=running&health=no_result&sort=-spend')
        ->assertInertia(fn (Assert $p) => $p->where('filters.health', 'no_result')->has('result.data', 1)->where('result.data.0.id', $dead->id));
});

it('answers the changed-today preset from the write log', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    $changed = crAd($acc, ['2026-10-04' => [100, 0, 0, 0]], ['status' => 'PAUSED', 'effective_status' => 'PAUSED']);
    crAd($acc, ['2026-10-04' => [100, 0, 0, 0]]);
    AdWriteAction::factory()->create(['ad_account_id' => $acc->id, 'target_level' => 'ad', 'target_external_id' => $changed->external_id,
        'state' => 'succeeded', 'to_status' => 'paused', 'confirmed_at' => now()->subHour()]);

    $this->actingAs(crAdmin())->get('/ads/explorer?changed=today&status=all')
        ->assertInertia(fn (Assert $p) => $p->has('result.data', 1)->where('result.data.0.id', $changed->id));
});

it('renders the campaign tree on view=tree', function () {
    crAd(AdAccount::factory()->meta()->create(['currency' => 'EGP']), ['2026-10-04' => [100, 0, 0, 0]]);

    $this->actingAs(crAdmin())->get('/ads/explorer?view=tree')
        ->assertInertia(fn (Assert $p) => $p->where('filters.view', 'tree')->where('result', null)->has('tree', 1)->has('tree.0.can_write'));
});

it('sorts ascending without the minus sign and keeps per_page to the allowed values', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    crAd($acc, ['2026-10-04' => [900, 0, 0, 0]]);
    $b = crAd($acc, ['2026-10-04' => [100, 0, 0, 0]]);

    $this->actingAs(crAdmin())->get('/ads/explorer?sort=spend&per_page=7')
        ->assertInertia(fn (Assert $p) => $p->where('filters.sort', 'spend')->where('filters.per_page', 25)->where('result.data.0.id', $b->id));
});

it('shows a buyer only the ads of accounts they hold', function () {
    $w = crBuyer();
    $own = crAd($w['account'], ['2026-10-04' => [100, 0, 0, 0]]);
    crAd(AdAccount::factory()->meta()->create(), ['2026-10-04' => [900, 0, 0, 0]]);

    $this->actingAs($w['user'])->get('/ads/explorer')
        ->assertInertia(fn (Assert $p) => $p->has('result.data', 1)->where('result.data.0.id', $own->id));
});
