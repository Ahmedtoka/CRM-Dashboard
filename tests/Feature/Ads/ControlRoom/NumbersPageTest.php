<?php

require_once __DIR__.'/../../../Support/AdsControlRoom.php';

use App\Models\AdAccount;
use App\Models\AdCampaign;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => crSetup($this));

it('renders the range report with buyers compare and chat campaigns from synced spend', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    $ad = crAd($acc, ['2026-10-02' => [300, 1, 100, 0]]);
    AdCampaign::whereKey($ad->ad_campaign_id)->update(['name' => 'Eid Abaya']);

    $this->actingAs(crAdmin())->get('/ads/numbers')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Ads/Numbers')
        ->where('filters.range', 'this_month')->where('filters.from', '2026-10-01')
        ->has('overview.totals.real_roas')->has('overview.daily')->has('summary')->has('top_accounts', 1)->has('buyers')
        ->where('chat_campaigns.rows.0.campaign', 'Eid Abaya')->where('chat_campaigns.rows.0.spend', 300)
        ->where('chat_campaigns.spend_available', true));
});

it('hides the chat campaigns section from media buyers', function () {
    $this->actingAs(crBuyer()['user'])->get('/ads/numbers')->assertOk()->assertInertia(fn (Assert $p) => $p->where('chat_campaigns', null));
});

it('passes the section through for deep links', function () {
    $this->actingAs(crAdmin())->get('/ads/numbers?section=buyers')->assertInertia(fn (Assert $p) => $p->where('filters.section', 'buyers'));
});
