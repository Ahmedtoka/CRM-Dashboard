<?php

require_once __DIR__.'/../../../Support/AdsControlRoom.php';

use App\Enums\UserRole;
use App\Models\AdAccount;
use App\Models\MediaBuyer;

beforeEach(fn () => crSetup($this));

it('sends running-creatives links to the explorer table with an equivalent query', function () {
    $this->actingAs(crAdmin())->get('/ads/creatives?status=active&sort=roas&account=7&from=2026-09-01&to=2026-09-10&q=abaya&ad=12')
        ->assertRedirect('/ads/explorer?from=2026-09-01&to=2026-09-10&q=abaya&ad=12&view=table&status=running&accounts=7&sort=-roas');
    $this->actingAs(crAdmin())->get('/ads/creatives')->assertRedirect('/ads/explorer?view=table&status=all');
});

it('sends winners links to the explorer cards', function () {
    $this->actingAs(crAdmin())->get('/ads/winners?tier=loser&from=2026-09-20&to=2026-09-22')
        ->assertRedirect('/ads/explorer?from=2026-09-20&to=2026-09-22&view=cards&status=all&health=losing&sort=-roas');
    $this->actingAs(crAdmin())->get('/ads/winners')->assertRedirect('/ads/explorer?view=cards&status=all&health=top&sort=-roas');
});

it('sends campaign tree links to the explorer tree, keeping picked accounts', function () {
    $this->actingAs(crAdmin())->get('/ads/campaigns?accounts[]=3&accounts[]=4&sort=roas')
        ->assertRedirect('/ads/explorer?accounts=3%2C4&view=tree&status=all&sort=-roas');
});

it('sends the actions page to decisions and the buyers list to numbers', function () {
    $this->actingAs(crAdmin())->get('/ads/actions')->assertRedirect('/ads/decisions');
    $this->actingAs(crAdmin())->get('/ads/buyers?range=this_month')->assertRedirect('/ads/numbers?range=this_month&section=buyers');
});

it('moves /reports/ads permanently to the numbers page', function () {
    $this->actingAs(crUser(UserRole::Supervisor))->get('/reports/ads?from=2026-09-01&to=2026-09-30&platform=facebook')
        ->assertStatus(301)->assertRedirect('/ads/numbers?from=2026-09-01&to=2026-09-30&section=chat');
});

it('keeps the creative JSON and the buyer detail page', function () {
    $ad = crAd(AdAccount::factory()->meta()->create());
    $this->actingAs(crAdmin())->getJson("/ads/creatives/{$ad->id}")->assertOk();
    $this->actingAs(crAdmin())->get('/ads/buyers/'.MediaBuyer::factory()->create()->id)->assertOk();
});
