<?php

require_once __DIR__.'/../../../Support/AdsControlRoom.php';

use App\Enums\UserRole;
use App\Models\AdAccount;
use App\Models\AdMaterial;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => crSetup($this));

/** 3 active days in the last 7 (2026-10-03..05): spend 1000/day, 1 purchase, 4000/day — smoothed ROAS 4 ≥ winner 2. */
function wmWinnerDays(float $value = 4000): array
{
    return ['2026-10-03' => [1000, 1, $value, 0], '2026-10-04' => [1000, 1, $value, 0], '2026-10-05' => [1000, 1, $value, 0]];
}

it('lists the materials linked to winner ads with their numbers, by roas', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    $winner = crAd($acc, wmWinnerDays(), ['name' => 'Eid reel ad', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE']);
    $better = crAd($acc, wmWinnerDays(8000), ['status' => 'ACTIVE', 'effective_status' => 'ACTIVE']);
    $loser = crAd(AdAccount::factory()->meta()->create(['currency' => 'EGP']), ['2026-10-03' => [1000, 0, 0, 0], '2026-10-04' => [1000, 0, 0, 0], '2026-10-05' => [1000, 0, 0, 0]]);
    crOrder($winner, '2026-10-04 12:00', 900);

    $good = AdMaterial::factory()->create(['title' => 'Eid reel', 'status' => 'live']);
    $good->ads()->attach($winner->id);
    $best = AdMaterial::factory()->create(['title' => 'Best reel']);
    $best->ads()->attach($better->id);
    $bad = AdMaterial::factory()->create(['title' => 'Losing reel']);
    $bad->ads()->attach($loser->id);
    AdMaterial::factory()->create(['title' => 'Unlinked']);

    $this->actingAs(crAdmin())->get('/ads/materials')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Ads/Materials/Index')
        ->has('winners', 2)
        ->where('winners.0.id', $best->id)
        ->has('winners.1', fn (Assert $w) => $w
            ->where('id', $good->id)->where('title', 'Eid reel')->where('product_title', null)->where('thumbnail', null)->where('status', 'live')
            ->where('performance.spend_tax', 3420)->where('performance.purchase_value', 12000)->where('performance.roas', 4)
            ->where('performance.real_orders', 1)->where('performance.winner_tier', 'winner')
            ->has('ads', 1)->where('ads.0.id', $winner->id)->where('ads.0.name', 'Eid reel ad')->where('ads.0.platform', 'meta')
            ->where('can_reuse', true)));
});

it('falls back to promising ads when fewer than three materials win, never to losers', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    // value 1500/day on an account averaging 1.5: smoothed (4500 + 1.5 × 500) ÷ 3500 = 1.5 → promising (≥ 1.3, < 2)
    $promising = crAd($acc, wmWinnerDays(1500));
    $loser = crAd(AdAccount::factory()->meta()->create(['currency' => 'EGP']), ['2026-10-03' => [1000, 0, 0, 0], '2026-10-04' => [1000, 0, 0, 0], '2026-10-05' => [1000, 0, 0, 0]]);
    $m = AdMaterial::factory()->create(['title' => 'Promising reel']);
    $m->ads()->attach($promising->id);
    AdMaterial::factory()->create(['title' => 'Losing reel'])->ads()->attach($loser->id);

    $this->actingAs(crAdmin())->get('/ads/materials')->assertInertia(fn (Assert $p) => $p
        ->has('winners', 1)->where('winners.0.id', $m->id)->where('winners.0.performance.winner_tier', 'promising'));
});

it('gives a viewer who cannot see spend an empty library', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    AdMaterial::factory()->create()->ads()->attach(crAd($acc, wmWinnerDays())->id);

    $this->actingAs(crUser(UserRole::Content))->get('/ads/materials')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('canSeeSpend', false)->where('winners', []));
});
