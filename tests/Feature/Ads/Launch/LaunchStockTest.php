<?php

use App\Ads\Launch\LaunchState;
use App\Ads\Materials\StockWatcher;
use App\Models\AdWriteAction;
use App\Models\ProductVariant;
use App\Models\UserNotification;
use Tests\Support\LaunchWorld;

beforeEach(fn () => LaunchWorld::boot());

it('TC-12: out of stock puts pre-live launches on hold, blocks approve, and releases them on restock', function () {
    $w = LaunchWorld::make();
    $waiting = LaunchWorld::launch($w, LaunchState::AwaitingApproval);
    $draft = LaunchWorld::launch($w, LaunchState::Draft, ['captions' => [LaunchWorld::caption(4)]]);
    ProductVariant::query()->update(['inventory_quantity' => 0]);

    expect(app(StockWatcher::class)->holds())->toBe(['held' => 2, 'released' => 0])
        ->and($waiting->fresh()->state)->toBe(LaunchState::OnHold)->and($waiting->fresh()->hold_from_state)->toBe(LaunchState::AwaitingApproval)
        ->and(UserNotification::where('type', 'ads.launch.on_hold')->count())->toBeGreaterThan(0);

    $this->actingAs($w['manager'])->withSession(LaunchWorld::confirmed())->postJson("/ads/approvals/{$waiting->public_id}/approve", [
        'revision' => $waiting->revision, 'checks_hash' => str_repeat('a', 64), 'ack_warnings' => [],
    ])->assertStatus(409)->assertJsonPath('code', 'launch_state');

    ProductVariant::query()->update(['inventory_quantity' => 9]);
    expect(app(StockWatcher::class)->holds())->toBe(['held' => 0, 'released' => 2])
        ->and($waiting->fresh()->state)->toBe(LaunchState::AwaitingApproval)->and($draft->fresh()->state)->toBe(LaunchState::Draft)
        ->and(UserNotification::where('type', 'ads.launch.released')->count())->toBeGreaterThan(0);
});

it('E9: the expiry clock keeps running on hold', function () {
    $w = LaunchWorld::make();
    $waiting = LaunchWorld::launch($w, LaunchState::AwaitingApproval);
    $deadline = $waiting->expires_at;
    ProductVariant::query()->update(['inventory_quantity' => 0]);
    app(StockWatcher::class)->holds();

    expect($waiting->fresh()->expires_at->equalTo($deadline))->toBeTrue();
});

it('D6: a live launch out of stock is flagged with a one-click Stop link, never stopped automatically', function () {
    $w = LaunchWorld::make();
    $live = LaunchWorld::launch($w, LaunchState::Live);
    ProductVariant::query()->update(['inventory_quantity' => 0]);

    expect(app(StockWatcher::class)->run())->toBe(['flagged' => 1, 'cleared' => 0]);

    $note = UserNotification::where('type', 'ads.need_stop')->where('user_id', $w['buyerUser']->id)->sole();
    expect($note->data['launch_ids'])->toBe([$live->public_id])
        ->and($note->data['link'])->toBe('/ads/launches?box=live&material='.$w['material']->id.'&stop=1')
        ->and($live->fresh()->state)->toBe(LaunchState::Live)
        ->and(AdWriteAction::count())->toBe(0);
});
