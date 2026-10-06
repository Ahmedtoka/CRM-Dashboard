<?php

use App\Ads\Alerts\AlertScope;
use App\Enums\UserRole;
use App\Models\AdAccountAssignment;
use App\Models\AdsAlert;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\AlertWorld as W;

beforeEach(fn () => W::freeze());

/** @return array{mine: AdsAlert, other: AdsAlert, inbox: AdsAlert, buyer: User} */
function scWorld(): array
{
    $acc = W::account();
    $buyer = W::buyer($acc);

    return [
        'mine' => AdsAlert::factory()->create(['ad_id' => W::ad($acc)->id]),
        'other' => AdsAlert::factory()->create(['ad_id' => W::ad(W::account())->id]),
        'inbox' => AdsAlert::factory()->create(['ad_id' => null, 'entity_level' => 'account', 'entity_id' => $acc->id, 'ad_account_id' => $acc->id, 'rule_id' => 'msg.inbox_slow_for_ads']),
        'buyer' => $buyer,
    ];
}

it('shows everything to admins with Ads authority and to supervisors', function (UserRole $role, bool $authority) {
    scWorld();
    $u = User::factory()->create(['role' => $role, 'ads_authority' => $authority]);

    expect(app(AlertScope::class)->visible($u)->count())->toBe(3);
})->with([[UserRole::Admin, true], [UserRole::Supervisor, false]]);

it('shows a buyer only the accounts held today, without owner-only rules', function () {
    $w = scWorld();

    expect(app(AlertScope::class)->visible($w['buyer'])->pluck('ads_alerts.id')->all())->toBe([$w['mine']->id]);
    expect(fn () => app(AlertScope::class)->find($w['buyer'], $w['other']->id))->toThrow(NotFoundHttpException::class);
});

it('hides an account from a buyer whose assignment ended yesterday', function () {
    $w = scWorld();
    AdAccountAssignment::query()->update(['ends_on' => W::day(-1)]);

    expect(app(AlertScope::class)->visible($w['buyer'])->count())->toBe(0);
});

it('shows nothing to content and moderator users', function (UserRole $role) {
    scWorld();

    expect(app(AlertScope::class)->visible(User::factory()->create(['role' => $role]))->count())->toBe(0);
})->with([UserRole::Content, UserRole::Moderator]);

it('keeps stock, product and spike dismissals for Ads authority', function () {
    $w = scWorld();
    $stock = AdsAlert::factory()->create(['ad_id' => $w['mine']->ad_id, 'rule_id' => 'all.out_of_stock']);
    $scope = app(AlertScope::class);

    expect($scope->canDismiss($w['buyer'], $stock))->toBeFalse()
        ->and($scope->canDismiss($w['buyer'], $w['mine']))->toBeTrue()
        ->and($scope->canDismiss(W::authority(), $stock))->toBeTrue()
        ->and($scope->canManage($w['buyer']))->toBeFalse()->and($scope->canManage(W::authority()))->toBeTrue()
        ->and($scope->canManage(User::factory()->create(['role' => UserRole::Supervisor])))->toBeFalse();
});

it('lists active feed users as notification recipients', function () {
    $w = scWorld();
    User::factory()->create(['role' => UserRole::Moderator]);
    User::factory()->create(['role' => UserRole::Supervisor, 'is_active' => false]);
    $admin = W::authority();

    expect(app(AlertScope::class)->recipients()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$w['buyer']->id, $admin->id])->sort()->values()->all());
});
