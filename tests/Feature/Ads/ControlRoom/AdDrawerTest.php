<?php

require_once __DIR__.'/../../../Support/AdsControlRoom.php';

use App\Models\AdAccount;
use App\Models\AdWriteAction;

beforeEach(fn () => crSetup($this));

it('returns the ad row, series, reasons, history and an empty funnel slot', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    $ad = crAd($acc, ['2026-10-04' => [300, 1, 200, 0]]);
    AdWriteAction::factory()->create([
        'ad_account_id' => $acc->id, 'target_level' => 'ad', 'target_external_id' => $ad->external_id, 'target_name' => $ad->name,
        'state' => 'succeeded', 'to_status' => 'paused', 'confirmed_at' => now()->subHour(),
    ]);

    $this->actingAs(crAdmin())->getJson("/ads/ad/{$ad->id}?range=last7")->assertOk()
        ->assertJsonPath('ad.id', $ad->id)
        ->assertJsonCount(14, 'ad.series')
        ->assertJsonPath('funnel', null)
        ->assertJsonCount(1, 'history')
        ->assertJsonPath('history.0.result', 'ok')
        ->assertJsonStructure(['ad' => ['health', 'can_write', 'objective', 'real_roas', 'preview_html'], 'reasons', 'decisions', 'levels']);
});

it('reads another buyer ad as not found', function () {
    $w = crBuyer();
    $foreign = crAd(AdAccount::factory()->meta()->create());

    $this->actingAs($w['user'])->getJson("/ads/ad/{$foreign->id}")->assertNotFound();
});
