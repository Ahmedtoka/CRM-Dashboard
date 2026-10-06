<?php

use App\Ads\Launch\LaunchCounters;
use App\Ads\Launch\LaunchState;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\LaunchWorld;

beforeEach(function () {
    LaunchWorld::boot();
    $this->withoutVite();
});

it('counts what waits for each role', function () {
    $w = LaunchWorld::make();
    LaunchWorld::launch($w, LaunchState::ChangesRequested);
    LaunchWorld::launch($w, LaunchState::BuyerReview, ['captions' => [LaunchWorld::caption(2)]]);
    LaunchWorld::launch($w, LaunchState::CreateFailed, ['captions' => [LaunchWorld::caption(3)]]);
    LaunchWorld::launch($w, LaunchState::AwaitingApproval, ['captions' => [LaunchWorld::caption(4)]]);
    LaunchWorld::launch($w, LaunchState::AwaitingApproval, ['captions' => [LaunchWorld::caption(5)]]);

    expect(LaunchCounters::for($w['content']))->toBe(['content_returned' => 1, 'buyer_review' => 0, 'awaiting_approval' => 0])
        ->and(LaunchCounters::for($w['buyerUser']))->toBe(['content_returned' => 0, 'buyer_review' => 2, 'awaiting_approval' => 0])
        ->and(LaunchCounters::for($w['manager']))->toBe(['content_returned' => 0, 'buyer_review' => 0, 'awaiting_approval' => 2])
        ->and(LaunchCounters::for($w['supervisor']))->toBe(['content_returned' => 0, 'buyer_review' => 0, 'awaiting_approval' => 0]);
});

it('shares the counters and the approve abilities with Inertia pages; never with inbox agents', function () {
    $w = LaunchWorld::make();
    LaunchWorld::launch($w, LaunchState::AwaitingApproval);

    $this->actingAs($w['manager'])->get('/ads/materials')->assertInertia(fn (Assert $p) => $p
        ->where('adsCounters.awaiting_approval', 1)->where('ads.canApprove', true)->where('ads.canDirectPublish', false));
    $this->actingAs($w['admin'])->get('/ads/materials')->assertInertia(fn (Assert $p) => $p->where('ads.canDirectPublish', true));
    $this->actingAs($w['content'])->get('/ads/materials')->assertInertia(fn (Assert $p) => $p->where('adsCounters.content_returned', 0)->where('ads.canApprove', false));
    $this->actingAs($w['agent'])->get('/inbox')->assertInertia(fn (Assert $p) => $p->where('adsCounters', null));
});
