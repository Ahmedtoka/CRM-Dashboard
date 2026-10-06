<?php

use App\Ads\AdsSettings;
use App\Ads\Launch\AccountBuyer;
use App\Ads\Launch\LaunchPolicy;
use App\Ads\Launch\LaunchSettings;
use App\Ads\Launch\LaunchState;
use App\Enums\UserRole;
use App\Models\AdAccountAssignment;
use App\Models\AdLaunch;
use App\Models\AdMaterial;
use App\Models\MediaBuyer;
use App\Models\User;
use Tests\Support\LaunchWorld;

beforeEach(fn () => LaunchWorld::boot());

it('finds the buyer holding an account today, never an ended, future or inactive one', function () {
    $w = LaunchWorld::make();
    expect(AccountBuyer::today($w['account'])?->id)->toBe($w['buyer']->id);

    AdAccountAssignment::query()->update(['ends_on' => now('Africa/Cairo')->subDay()->toDateString()]);
    expect(AccountBuyer::today($w['account']))->toBeNull();

    AdAccountAssignment::query()->update(['ends_on' => null, 'starts_on' => now('Africa/Cairo')->addDay()->toDateString()]);
    expect(AccountBuyer::today($w['account']))->toBeNull();

    AdAccountAssignment::query()->update(['starts_on' => '2026-01-01']);
    $w['buyerUser']->update(['is_active' => false]);
    expect(AccountBuyer::today($w['account']))->toBeNull();
});

it('reads the launch settings with defaults', function () {
    $s = app(LaunchSettings::class);
    expect($s->expiryDays())->toBe(7)->and($s->lowStockUnits())->toBe(10);

    app(AdsSettings::class)->set(LaunchSettings::EXPIRY_KEY, 3);
    app(AdsSettings::class)->set(LaunchSettings::LOW_STOCK_KEY, 4);
    expect($s->expiryDays())->toBe(3)->and($s->lowStockUnits())->toBe(4);
});

it('shows each role only its launches', function () {
    $w = LaunchWorld::make();
    $mine = LaunchWorld::launch($w, LaunchState::BuyerReview);
    $otherContent = User::factory()->create(['role' => UserRole::Content]);
    $otherMaterial = AdMaterial::factory()->create(['created_by_id' => $otherContent->id]);
    $foreign = LaunchWorld::launch($w, LaunchState::Draft, ['ad_material_id' => $otherMaterial->id, 'prepared_by_id' => $otherContent->id]);
    $otherBuyerUser = User::factory()->create(['role' => UserRole::MediaBuyer]);
    MediaBuyer::factory()->create(['user_id' => $otherBuyerUser->id]);

    $ids = fn (User $u) => LaunchPolicy::visible(AdLaunch::query(), $u)->orderBy('id')->pluck('id')->all();

    expect($ids($w['content']))->toBe([$mine->id])
        ->and($ids($otherContent))->toBe([$foreign->id])
        ->and($ids($w['buyerUser']))->toBe([$mine->id, $foreign->id]) // both on the account Ali holds
        ->and($ids($otherBuyerUser))->toBe([])
        ->and($ids($w['supervisor']))->toBe([$mine->id, $foreign->id])
        ->and($ids($w['agent']))->toBe([])
        ->and(LaunchPolicy::canSee($w['content'], $foreign))->toBeFalse();
});

it('knows who prepares, reviews and approves', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::BuyerReview);

    expect(LaunchPolicy::canPrepare($w['content']))->toBeTrue()->and(LaunchPolicy::canPrepare($w['agent']))->toBeFalse()
        ->and(LaunchPolicy::isReviewer($w['buyerUser'], $l))->toBeTrue()
        ->and(LaunchPolicy::isReviewer($w['manager'], $l))->toBeTrue()
        ->and(LaunchPolicy::isReviewer($w['supervisor'], $l))->toBeFalse()
        ->and(LaunchPolicy::isReviewer($w['content'], $l))->toBeFalse()
        ->and(LaunchPolicy::canApprove($w['manager']))->toBeTrue()->and(LaunchPolicy::canApprove($w['buyerUser']))->toBeFalse()
        ->and(LaunchPolicy::canEditDraft($w['content'], $l))->toBeTrue()->and(LaunchPolicy::canEditDraft($w['buyerUser'], $l))->toBeFalse();
});
