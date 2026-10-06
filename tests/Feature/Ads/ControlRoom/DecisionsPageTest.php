<?php

require_once __DIR__.'/../../../Support/AdsControlRoom.php';

use App\Ads\Control\StopAdvisor;
use App\Ads\Decisions\DecisionCounter;
use App\Ads\Decisions\PendingApprovals;
use App\Enums\UserRole;
use App\Models\AdAccount;
use App\Models\AdWriteAction;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => crSetup($this));

it('shows approvals on top, stop suggestions and an empty alerts slot to a manager', function () {
    $this->mock(PendingApprovals::class, function ($m) {
        $m->shouldReceive('canApprove')->andReturn(true);
        $m->shouldReceive('count')->andReturn(3);
        $m->shouldReceive('items')->andReturn([]);
    });
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    $dead = crAd($acc, ['2026-10-01' => [1500, 0, 0, 0]]);

    $this->actingAs(crAdmin())->get('/ads/decisions')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Ads/Decisions')->where('filters.tab', 'open')
        ->where('approvals.count', 3)->where('approvals.href', '/ads/approvals')->where('approvals.items', [])
        ->where('suggestions.0.ad_id', $dead->id)->has('suggestions.0.can_write')->has('suggestions.0.thumbnail_url')
        ->where('alerts', [])->where('counts.open', 4)->where('counts.snoozed', 0)->where('counts.closed', 0)->where('log', []));
});

it('shows the old actions log on the log tab, scoped to the buyer', function () {
    $w = crBuyer();
    $mine = crAd($w['account']);
    $foreign = AdAccount::factory()->meta()->create();
    foreach ([[$w['account'], $mine->external_id], [$foreign, '999']] as [$acc, $ext]) {
        AdWriteAction::factory()->create(['ad_account_id' => $acc->id, 'target_level' => 'ad', 'target_external_id' => $ext, 'state' => 'succeeded', 'to_status' => 'paused', 'confirmed_at' => now()]);
    }

    $this->actingAs($w['user'])->get('/ads/decisions?tab=log')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('filters.tab', 'log')->where('approvals', null)->has('log', 1)->where('log.0.external_id', $mine->external_id)->where('suggestions', []));
});

it('falls back to the open tab on an unknown tab', function () {
    $this->actingAs(crAdmin())->get('/ads/decisions?tab=nope')->assertInertia(fn (Assert $p) => $p->where('filters.tab', 'open'));
});

it('shares the cached nav badge count with buyers and managers only', function () {
    $w = crBuyer();
    crAd($w['account'], ['2026-10-01' => [1500, 0, 0, 0]]);

    // Nothing cached yet: no badge, and nothing computed to get one.
    $this->actingAs($w['user'])->get('/ads')->assertInertia(fn (Assert $p) => $p->where('adsDecisions', null));
    // The Decisions visit writes the count; every page then reads it.
    $this->actingAs($w['user'])->get('/ads/decisions')->assertOk();
    $this->actingAs($w['user'])->get('/ads')->assertInertia(fn (Assert $p) => $p->where('adsDecisions', 1));
    $this->actingAs(crUser(UserRole::Moderator))->get('/inbox')->assertInertia(fn (Assert $p) => $p->where('adsDecisions', null));
});

it('never runs StopAdvisor for the badge on a non-ads page (controller ruling)', function () {
    $admin = crAdmin();
    // StopAdvisor is final (no mock): the page must never even resolve it.
    $resolved = 0;
    $this->app->afterResolving(StopAdvisor::class, function () use (&$resolved) {
        $resolved++;
    });

    $this->actingAs($admin)->get('/inbox')->assertOk()->assertInertia(fn (Assert $p) => $p->where('adsDecisions', null));

    DecisionCounter::store($admin, 7);
    $this->actingAs($admin)->get('/inbox')->assertOk()->assertInertia(fn (Assert $p) => $p->where('adsDecisions', 7));
    expect($resolved)->toBe(0);
});

it('refreshes every badge from the hourly command and drops the viewer badge after a confirmed write', function () {
    $w = crBuyer();
    crAd($w['account'], ['2026-10-01' => [1500, 0, 0, 0]]);
    $moderator = crUser(UserRole::Moderator);

    $this->artisan('ads:decisions-count')->assertSuccessful();

    expect(DecisionCounter::cached($w['user']))->toBe(1)->and(DecisionCounter::cached($moderator))->toBeNull();
    DecisionCounter::forget($w['user']);
    expect(DecisionCounter::cached($w['user']))->toBeNull();
});

it('reads no approvals while the S1 launch classes are absent (merge guard)', function () {
    $approvals = app(PendingApprovals::class);

    expect(class_exists(PendingApprovals::COUNTERS))->toBeFalse()
        ->and($approvals->available())->toBeFalse()
        ->and($approvals->count(crAdmin()))->toBe(0)
        ->and($approvals->items(crAdmin()))->toBe([])
        ->and($approvals->canApprove(crUser(UserRole::Supervisor)))->toBeFalse()
        ->and($approvals->canApprove(crUser(UserRole::Supervisor, true)))->toBeTrue();

    $this->actingAs(crAdmin())->get('/ads/decisions')->assertInertia(fn (Assert $p) => $p
        ->where('approvals.count', 0)->where('approvals.items', []));
});
