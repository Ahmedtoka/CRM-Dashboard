<?php

use App\Ads\Launch\LaunchState;
use App\Enums\UserRole;
use App\Models\AdMaterial;
use App\Models\MediaBuyer;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\LaunchWorld;

beforeEach(function () {
    LaunchWorld::boot();
    $this->withoutVite();
});

it('TC-17: content sees the status of its own launches only, without money', function () {
    $w = LaunchWorld::make();
    $mine = LaunchWorld::launch($w, LaunchState::AwaitingApproval);
    $other = User::factory()->create(['role' => UserRole::Content]);
    $foreign = LaunchWorld::launch($w, LaunchState::Draft, ['ad_material_id' => AdMaterial::factory()->create(['created_by_id' => $other->id])->id, 'prepared_by_id' => $other->id]);

    $this->actingAs($w['content'])->get('/ads/launches')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Ads/Launches')->where('box', 'mine')->has('launches.data', 1)
        ->where('launches.data.0.id', $mine->public_id)->where('launches.data.0.state', 'awaiting_approval')
        ->where('launches.data.0.money', null)->where('launches.data.0.can.approve', false));
    $this->actingAs($w['content'])->getJson("/ads/launches/{$foreign->public_id}")->assertNotFound();
    $this->actingAs($w['content'])->get('/ads/approvals')->assertRedirect();
});

it('shows the buyer the launches under review on the accounts held today, with the diff base', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::BuyerReview, ['original' => ['ad_set_id' => null, 'file_ids' => [], 'captions' => [LaunchWorld::caption(0)]]]);
    $stranger = User::factory()->create(['role' => UserRole::MediaBuyer]);
    MediaBuyer::factory()->create(['user_id' => $stranger->id]);

    $this->actingAs($w['buyerUser'])->get('/ads/launches')->assertInertia(fn (Assert $p) => $p
        ->where('box', 'review')->where('canReview', true)->has('launches.data', 1)
        ->where('launches.data.0.can.forward', true)->where('launches.data.0.can.send_back', true)
        ->where('launches.data.0.original.captions.0.headline', 'عباية حرير 0')->where('launches.data.0.captions.0.headline', 'عباية حرير 1'));
    $this->actingAs($stranger)->get('/ads/launches?box=review')->assertInertia(fn (Assert $p) => $p->has('launches.data', 0));
});

it('serves the approvals page to Ads authority with fresh checks and a hash the approve accepts', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::AwaitingApproval);
    LaunchWorld::launch($w, LaunchState::OnHold, ['hold_from_state' => LaunchState::AwaitingApproval, 'awaiting_at' => now()->subMinutes(10), 'captions' => [LaunchWorld::caption(6)]]);

    $res = $this->actingAs($w['manager'])->get('/ads/approvals')->assertOk();
    $res->assertInertia(fn (Assert $p) => $p->component('Ads/Approvals')->has('launches', 2)->where('canApprove', true)->where('writesOn', true)
        ->where('approvalsLeft', 20)->where('launches.0.can.approve', true)->where('launches.1.can.approve', false)
        ->where('launches.0.money.cap', 20000)->has('launches.0.history')->has('launches.0.publications', 1));
    $card = $res->viewData('page')['props']['launches'][0];

    $this->actingAs($w['manager'])->withSession(LaunchWorld::confirmed())->postJson("/ads/approvals/{$card['id']}/approve", [
        'revision' => $card['revision'], 'checks_hash' => $card['checks_hash'], 'ack_warnings' => [],
    ])->assertOk();
});

it('filters approvals by buyer, account, age, failing check and expiry', function () {
    $w = LaunchWorld::make();
    $old = LaunchWorld::launch($w, LaunchState::AwaitingApproval, ['awaiting_at' => now()->subDays(4), 'expires_at' => now()->addHours(10)]);
    LaunchWorld::launch($w, LaunchState::AwaitingApproval, ['captions' => [['headline' => 'H', 'primary_text' => 'بـ 999 جنيه', 'cta' => 'SHOP_NOW']]]);

    $ids = fn (string $q) => collect($this->actingAs($w['manager'])->get('/ads/approvals'.$q)->viewData('page')['props']['launches'])->pluck('id')->all();
    expect($ids('?age=3d'))->toBe([$old->public_id])->and($ids('?expiring=1'))->toBe([$old->public_id])
        ->and($ids('?fails=1'))->toHaveCount(1)->not->toContain($old->public_id)
        ->and($ids('?buyer='.$w['buyer']->id))->toHaveCount(2)->and($ids('?account=999999'))->toBe([]);
});

it('lets a supervisor without authority read the approvals; refuses buyers and agents', function () {
    $w = LaunchWorld::make();
    LaunchWorld::launch($w, LaunchState::AwaitingApproval);

    $this->actingAs($w['supervisor'])->get('/ads/approvals')->assertOk()->assertInertia(fn (Assert $p) => $p->where('canApprove', false)->where('launches.0.can.approve', false));
    $this->actingAs($w['buyerUser'])->get('/ads/approvals')->assertForbidden();
    $this->actingAs($w['agent'])->get('/ads/approvals')->assertForbidden();
});

it('serves editor options, fresh checks and the launch itself', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w);

    $this->actingAs($w['content'])->getJson("/ads/launches/options?material={$w['material']->id}")->assertOk()
        ->assertJsonPath('slots.0.id', $w['adset']->id)->assertJsonCount(2, 'files')->assertJsonPath('files.0.width', 1080)
        ->assertJsonPath('max_captions', 5)->assertJsonPath('ctas.0', 'SHOP_NOW');
    $this->actingAs($w['content'])->getJson("/ads/launches/{$l->public_id}/checks")->assertOk()->assertJsonStructure(['checks' => [['key', 'level', 'message_ar', 'message_en']], 'checks_hash']);
    $this->actingAs($w['content'])->getJson("/ads/launches/{$l->public_id}")->assertOk()->assertJsonPath('launch.id', $l->public_id)->assertJsonPath('launch.can.submit', true)
        ->assertJsonPath('launch.files.0.id', $w['files'][0]->id);
    $this->actingAs($w['agent'])->getJson("/ads/launches/options?material={$w['material']->id}")->assertForbidden();
});

it('lists the open launches on the Library rows', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::BuyerReview);

    $this->actingAs($w['content'])->get('/ads/materials')->assertInertia(fn (Assert $p) => $p
        ->where('materials.data.0.launches.0.id', $l->public_id)->where('materials.data.0.launches.0.state', 'buyer_review')
        ->where('materials.data.0.launches.0.adset', 'Broad | EG | Advantage+'));
});
