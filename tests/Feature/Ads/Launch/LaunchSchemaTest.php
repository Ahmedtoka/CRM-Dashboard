<?php

use App\Ads\Launch\LaunchState;
use App\Models\AdLaunch;
use App\Models\AdMaterial;
use App\Models\AdSet;
use Illuminate\Support\Facades\Schema;

it('creates the launch table and the new columns', function () {
    expect(Schema::hasTable('ad_launches'))->toBeTrue()
        ->and(Schema::hasColumns('ad_launches', [
            'public_id', 'ad_material_id', 'ad_account_id', 'ad_set_id', 'campaign_external_id', 'campaign_name', 'adset_external_id',
            'adset_name', 'identity', 'link', 'file_ids', 'captions', 'original', 'state', 'hold_from_state', 'revision', 'checks',
            'checks_hash', 'prepared_by_id', 'reviewer_buyer_id', 'forwarded_by_id', 'decided_by_id', 'decision_code', 'decision_reason',
            'self_approved', 'last_error', 'submitted_at', 'forwarded_at', 'awaiting_at', 'decided_at', 'approved_at', 'expires_at',
            'expiring_notified_at', 'live_at', 'stopped_at', 'retired_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('ad_publications', ['ad_launch_id', 'run_write_action_id', 'archived_at']))->toBeTrue()
        ->and(Schema::hasColumns('ad_sets', ['open_for_drafts_at', 'open_for_drafts_by_id']))->toBeTrue()
        ->and(Schema::hasColumns('ad_materials', ['retired_by_id', 'retire_reason']))->toBeTrue();
});

it('gives a launch a ulid public id used as the route key and casts its state', function () {
    $m = AdMaterial::factory()->create();
    $l = AdLaunch::create([
        'ad_material_id' => $m->id, 'state' => LaunchState::Draft, 'file_ids' => [1, 2],
        'captions' => [['headline' => 'H', 'primary_text' => 'T', 'cta' => 'SHOP_NOW'], ['headline' => 'H2', 'primary_text' => 'T2', 'cta' => 'SHOP_NOW']],
    ]);

    $fresh = $l->fresh();
    expect($l->public_id)->toHaveLength(26)->and($l->getRouteKey())->toBe($l->public_id)
        ->and($fresh->state)->toBe(LaunchState::Draft)->and($fresh->revision)->toBe(1)->and($fresh->self_approved)->toBeFalse()
        ->and($fresh->adsCount())->toBe(4)->and($m->launches()->count())->toBe(1);
});

it('allows only the lifecycle transitions', function (LaunchState $from, LaunchState $to, bool $ok) {
    expect($from->canMoveTo($to))->toBe($ok);
})->with([
    'submit' => [LaunchState::Draft, LaunchState::BuyerReview, true],
    'draft cannot launch' => [LaunchState::Draft, LaunchState::Launching, false],
    'forward' => [LaunchState::BuyerReview, LaunchState::CreatingPaused, true],
    'buyer edit' => [LaunchState::BuyerReview, LaunchState::BuyerReview, true],
    'created' => [LaunchState::CreatingPaused, LaunchState::AwaitingApproval, true],
    'approve' => [LaunchState::AwaitingApproval, LaunchState::Launching, true],
    'live' => [LaunchState::Launching, LaunchState::Live, true],
    'stop' => [LaunchState::Live, LaunchState::Stopped, true],
    'run again' => [LaunchState::Stopped, LaunchState::Live, true],
    'rejected is terminal' => [LaunchState::Rejected, LaunchState::BuyerReview, false],
    'release to approval' => [LaunchState::OnHold, LaunchState::AwaitingApproval, true],
    'launching never expires' => [LaunchState::Launching, LaunchState::Expired, false],
    'live is never held' => [LaunchState::Live, LaunchState::OnHold, false],
]);

it('knows terminal and pre-live states', function () {
    expect(LaunchState::Expired->isTerminal())->toBeTrue()->and(LaunchState::Live->isTerminal())->toBeFalse()
        ->and(LaunchState::AwaitingApproval->isPreLive())->toBeTrue()->and(LaunchState::Launching->isPreLive())->toBeFalse()
        ->and(LaunchState::TERMINAL_VALUES)->toBe(['retired', 'rejected', 'expired', 'withdrawn'])
        ->and(LaunchState::HOLDABLE_VALUES)->not->toContain('on_hold')->toContain('awaiting_approval');
});

it('scopes open slots', function () {
    $open = AdSet::factory()->create(['open_for_drafts_at' => now()]);
    AdSet::factory()->create();

    expect(AdSet::query()->openForDrafts()->pluck('id')->all())->toBe([$open->id]);
});
