<?php

use App\Ads\Launch\LaunchService;
use App\Ads\Launch\LaunchState;
use App\Ads\Launch\MaterialStatus;
use App\Models\Ad;
use App\Models\AdMaterial;
use Tests\Support\LaunchWorld;

beforeEach(fn () => LaunchWorld::boot());

it('derives the material status from its launches and running ads', function () {
    $w = LaunchWorld::make();
    $m = $w['material'];
    expect(MaterialStatus::derive($m))->toBe('new');

    $l = LaunchWorld::launch($w);
    expect(MaterialStatus::derive($m))->toBe('in_review');

    $l->forceFill(['state' => LaunchState::Stopped])->save();
    expect(MaterialStatus::derive($m))->toBe('paused');

    $l->forceFill(['state' => LaunchState::Withdrawn])->save();
    expect(MaterialStatus::derive($m))->toBe('new');

    $ad = Ad::factory()->create(['status' => 'ACTIVE']);
    $m->ads()->attach($ad->id);
    expect(MaterialStatus::derive($m))->toBe('live'); // an ad made outside the CRM, linked by hand
});

it('refreshes the stored status on every launch move', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::BuyerReview);

    app(LaunchService::class)->withdraw($w['buyerUser'], $l);
    expect($w['material']->fresh()->status)->toBe('new');

    $this->actingAs($w['content'])->postJson("/ads/materials/{$w['material']->id}/launches", ['adset_id' => $w['adset']->id, 'file_ids' => [$w['files'][0]->id], 'captions' => [LaunchWorld::caption()]])->assertCreated();
    expect($w['material']->fresh()->status)->toBe('in_review');
});

it('remaps the old statuses and back', function () {
    $ids = collect(['not_started', 'activated', 'done'])->map(fn ($s) => AdMaterial::factory()->create(['status' => $s])->id)->all();
    $migration = require database_path('migrations/2026_10_08_100050_remap_ad_material_statuses.php');

    $migration->up();
    expect(AdMaterial::whereIn('id', $ids)->orderBy('id')->pluck('status')->all())->toBe(['new', 'live', 'retired']);

    $migration->down();
    expect(AdMaterial::whereIn('id', $ids)->orderBy('id')->pluck('status')->all())->toBe(['not_started', 'activated', 'done']);
});

it('TC-16: refuses to delete a material with an open launch or a running ad; deletes it once all is terminal', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::Live);

    $this->actingAs($w['supervisor'])->delete("/ads/materials/{$w['material']->id}")->assertSessionHasErrors('material');
    expect(AdMaterial::find($w['material']->id))->not->toBeNull();

    Ad::query()->update(['status' => 'PAUSED']);
    $l->forceFill(['state' => LaunchState::Retired])->save();
    $this->actingAs($w['supervisor'])->delete("/ads/materials/{$w['material']->id}")->assertSessionHasNoErrors();
    expect(AdMaterial::find($w['material']->id))->toBeNull()->and($l->fresh()->ad_material_id)->toBeNull();
});

it('retires a material: live launches are stopped and retired, drafts withdrawn, who and why kept', function () {
    $w = LaunchWorld::make();
    $live = LaunchWorld::launch($w, LaunchState::Live);
    $draft = LaunchWorld::launch($w, LaunchState::Draft, ['captions' => [LaunchWorld::caption(5)]]);

    $this->actingAs($w['buyerUser'])->post("/ads/materials/{$w['material']->id}/retire", ['reason' => 'الموسم خلص'], ['Idempotency-Key' => 'ret-mat-0001'])->assertSessionHasNoErrors();

    $m = $w['material']->fresh();
    expect($m->status)->toBe('retired')->and($m->retired_by_id)->toBe($w['buyerUser']->id)->and($m->retire_reason)->toBe('الموسم خلص')
        ->and($live->fresh()->state)->toBe(LaunchState::Retired)->and($draft->fresh()->state)->toBe(LaunchState::Withdrawn);
});

it('refuses to retire while a launch waits for a decision', function () {
    $w = LaunchWorld::make();
    LaunchWorld::launch($w, LaunchState::AwaitingApproval);

    $this->actingAs($w['buyerUser'])->post("/ads/materials/{$w['material']->id}/retire", [], ['Idempotency-Key' => 'ret-mat-0002'])->assertSessionHasErrors('material');
});

it('has no manual status route any more', function () {
    $w = LaunchWorld::make();

    $this->actingAs($w['buyerUser'])->post("/ads/materials/{$w['material']->id}/status", ['status' => 'activated'])->assertNotFound();
});
