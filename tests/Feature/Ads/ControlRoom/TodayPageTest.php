<?php

require_once __DIR__.'/../../../Support/AdsControlRoom.php';

use App\Enums\UserRole;
use App\Models\AdAccount;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => crSetup($this));

it('renders Today for a manager with every block', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    $win = crAd($acc, ['2026-10-03' => [100, 1, 300, 0], '2026-10-06' => [20, 0, 0, 0]]);
    crOrder($win, '2026-10-03 13:00', 300);

    $this->actingAs(crAdmin())->get('/ads')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Ads/Today')
        ->where('filters.range', 'last7')->where('filters.from', '2026-09-29')->where('filters.to', '2026-10-05')
        ->has('today.decisions.suggestions')->where('today.decisions.alerts', [])->has('today.decisions.approvals')
        ->has('today.money_today.spend_so_far')->has('today.money_today.baseline')->has('today.money_today.conversations')
        ->has('today.last7.totals.real_roas')->has('today.last7.totals.roas')->has('today.last7.totals.losers_spend_share')
        ->has('today.buyers')
        ->where('today.best.0.id', $win->id)->has('today.best.0.series', 14)->has('today.best.0.health')
        ->has('today.worst')->has('freshness'));
});

it('scopes Today to the buyer own accounts and hides the buyers strip', function () {
    $w = crBuyer();
    $own = crAd($w['account'], ['2026-10-03' => [100, 1, 300, 0]]);
    crOrder($own, '2026-10-03 13:00', 300);
    $foreign = crAd(AdAccount::factory()->meta()->create(['currency' => 'EGP']), ['2026-10-03' => [100, 1, 300, 0]]);
    crOrder($foreign, '2026-10-03 13:00', 300);

    $this->actingAs($w['user'])->get('/ads')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Ads/Today')->where('today.buyers', null)
        ->has('today.best', 1)->where('today.best.0.id', $own->id));
});

it('keeps agents out and sends content to the library', function () {
    $this->actingAs(crUser(UserRole::Moderator))->get('/ads')->assertForbidden();
    $this->actingAs(crUser(UserRole::Content))->get('/ads')->assertRedirect();
});
