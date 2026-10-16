<?php

use App\Ads\Launch\LaunchHandoff;
use App\Ads\Launch\LaunchState;
use App\Enums\UserRole;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\MediaBuyer;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\LaunchWorld;

beforeEach(function () {
    LaunchWorld::boot();
    $this->withoutVite();
});

/** A second buyer holding a second account, with one launch waiting for them. */
function secondBuyer(array $w): array
{
    $user = User::factory()->create(['role' => UserRole::MediaBuyer, 'name' => 'Omar']);
    $buyer = MediaBuyer::factory()->create(['user_id' => $user->id, 'name' => 'Omar', 'is_active' => true]);
    $account = AdAccount::factory()->meta()->create(['name' => 'LV Two', 'currency' => 'EGP']);
    AdAccountAssignment::factory()->create(['ad_account_id' => $account->id, 'media_buyer_id' => $buyer->id, 'starts_on' => '2026-01-01', 'ends_on' => null]);

    return ['user' => $user, 'buyer' => $buyer, 'account' => $account];
}

it('counts the drafts waiting for each buyer, oldest first in the wait', function () {
    $w = LaunchWorld::make();
    $omar = secondBuyer($w);
    LaunchWorld::launch($w, LaunchState::BuyerReview, ['submitted_at' => now()->subHours(5)]);
    LaunchWorld::launch($w, LaunchState::CreateFailed, ['captions' => [LaunchWorld::caption(2)]]);
    LaunchWorld::launch($w, LaunchState::BuyerReview, ['ad_account_id' => $omar['account']->id, 'reviewer_buyer_id' => $omar['buyer']->id, 'captions' => [LaunchWorld::caption(3)]]);
    LaunchWorld::launch($w, LaunchState::AwaitingApproval, ['captions' => [LaunchWorld::caption(4)]]); // past the buyer: not counted
    LaunchWorld::launch($w, LaunchState::Draft, ['captions' => [LaunchWorld::caption(5)]]); // not sent yet

    $rows = LaunchHandoff::byBuyer($w['manager']);

    expect(array_map(fn ($r) => [$r['buyer_name'], $r['count']], $rows))->toBe([['Ali', 2], ['Omar', 1]])
        ->and($rows[0]['oldest_at'])->toBe(now()->subHours(5)->toIso8601String())
        ->and(LaunchHandoff::total($w['manager']))->toBe(3);
});

it('content sees only its own drafts waiting; a buyer only their own queue', function () {
    $w = LaunchWorld::make();
    $omar = secondBuyer($w);
    LaunchWorld::launch($w, LaunchState::BuyerReview);
    $other = User::factory()->create(['role' => UserRole::Content]);
    LaunchWorld::launch($w, LaunchState::BuyerReview, ['ad_account_id' => $omar['account']->id, 'reviewer_buyer_id' => $omar['buyer']->id, 'prepared_by_id' => $other->id, 'captions' => [LaunchWorld::caption(2)]]);

    expect(LaunchHandoff::total($w['content']))->toBe(1)
        ->and(LaunchHandoff::total($w['buyerUser']))->toBe(1)
        ->and(LaunchHandoff::total($omar['user']))->toBe(1)
        ->and(LaunchHandoff::total($w['agent']))->toBe(0);
});

it('the «جاهزة للنشر» box lists the waiting drafts, filtered per buyer, for content and managers', function () {
    $w = LaunchWorld::make();
    $omar = secondBuyer($w);
    $ali = LaunchWorld::launch($w, LaunchState::BuyerReview);
    LaunchWorld::launch($w, LaunchState::BuyerReview, ['ad_account_id' => $omar['account']->id, 'reviewer_buyer_id' => $omar['buyer']->id, 'captions' => [LaunchWorld::caption(2)]]);

    $this->actingAs($w['manager'])->get('/ads/launches?box=ready')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('box', 'ready')->has('launches.data', 2)->where('counts.ready', 2)->has('handoff', 2)->where('canSeeReady', true));
    $this->actingAs($w['manager'])->get('/ads/launches?box=ready&reviewer='.$w['buyer']->id)->assertInertia(fn (Assert $p) => $p
        ->has('launches.data', 1)->where('launches.data.0.id', $ali->public_id)->where('filters.reviewer', $w['buyer']->id));

    $this->actingAs($w['content'])->get('/ads/launches?box=ready')->assertInertia(fn (Assert $p) => $p
        ->where('box', 'ready')->has('launches.data', 2)->where('canSeeReady', true)); // both are hers (LaunchWorld)

    // a buyer already has «مستنية مراجعتك»: no second tab
    $this->actingAs($w['buyerUser'])->get('/ads/launches')->assertInertia(fn (Assert $p) => $p->where('canSeeReady', false));
});

it('puts the per-buyer waiting counts on Ads Today', function () {
    $w = LaunchWorld::make();
    LaunchWorld::launch($w, LaunchState::BuyerReview);

    $this->actingAs($w['manager'])->get('/ads')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('handoff.total', 1)->where('handoff.buyers.0.buyer_name', 'Ali')->where('handoff.buyers.0.count', 1));
    $this->actingAs($w['buyerUser'])->get('/ads')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('handoff.total', 1)->where('handoff.buyers', []));
});
