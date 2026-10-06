<?php

use App\Ads\AdsSettings;
use App\Ads\Control\Write\WriteLimits;
use App\Ads\Launch\LaunchState;
use App\Enums\UserRole;
use App\Models\User;
use Tests\Support\LaunchWorld;

beforeEach(fn () => LaunchWorld::boot());

/** The preparer has launched before, so the launch can be "safe". */
function baVeteran(array $w): void
{
    LaunchWorld::launch($w, LaunchState::Retired, ['captions' => [LaunchWorld::caption(90)]]);
}

it('TC-13: lists only clean launches of known preparers, oldest first, within the activations left', function () {
    $w = LaunchWorld::make();
    baVeteran($w);
    $safe = LaunchWorld::launch($w, LaunchState::AwaitingApproval, ['captions' => [LaunchWorld::caption(1)], 'awaiting_at' => now()->subHours(3)]);
    $warned = LaunchWorld::launch($w, LaunchState::AwaitingApproval, ['captions' => [['headline' => 'H', 'primary_text' => str_repeat('ب', 130), 'cta' => 'SHOP_NOW']]]);
    $newbie = User::factory()->create(['role' => UserRole::Content]);
    $first = LaunchWorld::launch($w, LaunchState::AwaitingApproval, ['captions' => [LaunchWorld::caption(3)], 'prepared_by_id' => $newbie->id]);
    $safe2 = LaunchWorld::launch($w, LaunchState::AwaitingApproval, ['captions' => [LaunchWorld::caption(4)], 'awaiting_at' => now()->subHour()]);
    app(AdsSettings::class)->set(WriteLimits::SETTING, ['global' => [], 'accounts' => [], 'users' => [(string) $w['manager']->id => ['activations_per_user_day' => 1]]]);

    $res = $this->actingAs($w['manager'])->withSession(LaunchWorld::confirmed())->postJson('/ads/approvals/bulk')->assertOk();

    expect($res->json('launches.*.id'))->toBe([$safe->public_id])->and($res->json('total_ads'))->toBe(1)->and($res->json('approvals_left'))->toBe(1)
        ->and($res->json('skipped'))->toBe(['warned' => 1, 'first_launch' => 1, 'self' => 0, 'limit' => 1]);

    $item = $res->json('launches.0');
    $this->actingAs($w['manager'])->withSession(LaunchWorld::confirmed())->postJson("/ads/approvals/{$item['id']}/approve", [
        'revision' => $item['revision'], 'checks_hash' => $item['checks_hash'], 'ack_warnings' => [],
    ])->assertOk()->assertJsonPath('launch.state', 'live');
    expect($warned->fresh()->state)->toBe(LaunchState::AwaitingApproval)->and($first->fresh()->state)->toBe(LaunchState::AwaitingApproval)
        ->and($safe2->fresh()->state)->toBe(LaunchState::AwaitingApproval);
});

it('needs the re-auth and Ads authority', function () {
    $w = LaunchWorld::make();

    $this->actingAs($w['manager'])->postJson('/ads/approvals/bulk')->assertStatus(423);
    $this->actingAs($w['buyerUser'])->withSession(LaunchWorld::confirmed())->postJson('/ads/approvals/bulk')->assertForbidden();
});

it('skips launches the approver prepared unless an admin approves them', function () {
    $w = LaunchWorld::make();
    baVeteran($w);
    LaunchWorld::launch($w, LaunchState::AwaitingApproval, ['captions' => [LaunchWorld::caption(1)], 'forwarded_by_id' => $w['manager']->id]);

    $this->actingAs($w['manager'])->withSession(LaunchWorld::confirmed())->postJson('/ads/approvals/bulk')->assertOk()->assertJsonPath('skipped.self', 1);
});
