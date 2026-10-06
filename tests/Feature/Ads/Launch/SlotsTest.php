<?php

use App\Ads\Launch\SlotService;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdCampaign;
use App\Models\AdsAuditLog;
use App\Models\AdSet;
use Tests\Support\LaunchWorld;

beforeEach(fn () => LaunchWorld::boot());

it('lets the buyer holding the account open and close a slot, audited', function () {
    $w = LaunchWorld::make();
    $set = AdSet::factory()->create(['ad_campaign_id' => $w['campaign']->id]);

    $this->actingAs($w['buyerUser'])->postJson("/ads/slots/{$set->id}", ['open' => true])->assertOk()->assertJsonPath('slot.open', true);
    expect($set->fresh()->open_for_drafts_at)->not->toBeNull()->and($set->fresh()->open_for_drafts_by_id)->toBe($w['buyerUser']->id);

    $this->actingAs($w['buyerUser'])->postJson("/ads/slots/{$set->id}", ['open' => false])->assertOk()->assertJsonPath('slot.open', false);
    expect($set->fresh()->open_for_drafts_at)->toBeNull()
        ->and(AdsAuditLog::whereIn('action', ['launch.slot_opened', 'launch.slot_closed'])->orderBy('id')->pluck('action')->all())->toBe(['launch.slot_opened', 'launch.slot_closed']);
});

it('refuses a buyer on another account, a supervisor without authority, content and agents; allows Ads authority', function () {
    $w = LaunchWorld::make();
    $otherAccount = AdAccount::factory()->meta()->create();
    $foreign = AdSet::factory()->create(['ad_campaign_id' => AdCampaign::factory()->create(['ad_account_id' => $otherAccount->id])->id]);

    $this->actingAs($w['buyerUser'])->postJson("/ads/slots/{$foreign->id}", ['open' => true])->assertForbidden()->assertJsonPath('code', 'out_of_scope');
    $this->actingAs($w['supervisor'])->postJson("/ads/slots/{$foreign->id}", ['open' => true])->assertForbidden();
    $this->actingAs($w['content'])->postJson("/ads/slots/{$foreign->id}", ['open' => true])->assertForbidden();
    $this->actingAs($w['agent'])->postJson("/ads/slots/{$foreign->id}", ['open' => true])->assertForbidden();
    $this->actingAs($w['manager'])->postJson("/ads/slots/{$foreign->id}", ['open' => true])->assertOk();
});

it('lists the slots a buyer may manage and the open slots content may pick', function () {
    $w = LaunchWorld::make();
    $closed = AdSet::factory()->create(['ad_campaign_id' => $w['campaign']->id, 'name' => 'Retarget | EG | 30d']);

    $this->actingAs($w['buyerUser'])->getJson('/ads/slots')->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonFragment(['id' => $closed->id, 'open' => false])->assertJsonFragment(['id' => $w['adset']->id, 'open' => true]);

    $open = app(SlotService::class)->openSlots();
    expect($open)->toHaveCount(1)->and($open[0]['id'])->toBe($w['adset']->id)->and($open[0]['buyer']['name'])->toBe('Ali')
        ->and($open[0]['account']['name'])->toBe('LV Main');
});

it('drops an open slot whose account has no buyer today', function () {
    $w = LaunchWorld::make();
    AdAccountAssignment::query()->delete();

    expect(app(SlotService::class)->openSlots())->toBe([]);
});
