<?php

use App\Ads\Access\AdsScope;
use App\Ads\Buyers\AssignmentService;
use App\Ads\Buyers\BuyerResolver;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdDailyMetric;
use App\Models\MediaBuyer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Validation\ValidationException;

function seedSeptember(AdAccount $acc): void
{
    $ad = Ad::factory()->for($acc, 'account')->create();
    foreach (CarbonPeriod::create('2026-09-01', '2026-09-30') as $d) {
        AdDailyMetric::factory()->create(['ad_id' => $ad->id, 'ad_account_id' => $acc->id, 'date' => $d->toDateString(), 'spend' => 100]);
    }
}

function septemberSplit(): \Illuminate\Support\Collection
{
    return app(BuyerResolver::class)->metricsWithBuyer(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'))
        ->selectRaw('SUM(m.spend) s')->groupBy('buyer_id')->pluck('s', 'buyer_id');
}

it('splits an account\'s spend between buyers across an owner change', function () {
    $acc = AdAccount::factory()->create();
    $ahmed = MediaBuyer::factory()->create(['name' => 'Ahmed Gamal']);
    $mostafa = MediaBuyer::factory()->create(['name' => 'Mostafa']);
    $svc = app(AssignmentService::class);
    $svc->assign($acc, $ahmed, CarbonImmutable::parse('2026-09-01'));
    $svc->assign($acc, $mostafa, CarbonImmutable::parse('2026-09-16'));
    seedSeptember($acc);
    $rows = septemberSplit();
    expect((float) $rows[$ahmed->id])->toBe(1500.0)->and((float) $rows[$mostafa->id])->toBe(1500.0);
    expect($svc->history($acc)[0]['buyer'])->toBe('Mostafa')->and($svc->history($acc)[1]['ends_on'])->toBe('2026-09-15');
});

it('rejects an assignment that starts before the open one', function () {
    $acc = AdAccount::factory()->create();
    $svc = app(AssignmentService::class);
    $svc->assign($acc, MediaBuyer::factory()->create(), CarbonImmutable::parse('2026-09-10'));
    expect(fn () => $svc->assign($acc, MediaBuyer::factory()->create(), CarbonImmutable::parse('2026-09-05')))
        ->toThrow(ValidationException::class);
    expect(AdAccountAssignment::count())->toBe(1);
});

it('treats days before the first assignment as unassigned', function () {
    $acc = AdAccount::factory()->create();
    $ahmed = MediaBuyer::factory()->create();
    app(AssignmentService::class)->assign($acc, $ahmed, CarbonImmutable::parse('2026-09-21'));
    seedSeptember($acc);
    $rows = septemberSplit();
    expect((float) $rows[$ahmed->id])->toBe(1000.0)->and((float) $rows[''])->toBe(2000.0);
});

it('unassigns from a date by closing the open assignment', function () {
    $acc = AdAccount::factory()->create();
    $ahmed = MediaBuyer::factory()->create();
    $svc = app(AssignmentService::class);
    $svc->assign($acc, $ahmed, CarbonImmutable::parse('2026-09-01'));
    expect($svc->assign($acc, null, CarbonImmutable::parse('2026-09-11')))->toBeNull();
    expect($svc->history($acc))->toHaveCount(1)->and($svc->history($acc)[0]['ends_on'])->toBe('2026-09-10');
    seedSeptember($acc);
    $rows = septemberSplit();
    expect((float) $rows[$ahmed->id])->toBe(1000.0)->and((float) $rows[''])->toBe(2000.0);
    // after an unassign the account can be handed out again, but not into the closed period
    expect(fn () => $svc->assign($acc, $ahmed, CarbonImmutable::parse('2026-09-10')))->toThrow(ValidationException::class);
    $svc->assign($acc, $ahmed, CarbonImmutable::parse('2026-09-21'));
    expect($svc->history($acc))->toHaveCount(2);
});

it('is a no-op for the current holder and corrects a same-day start', function () {
    $acc = AdAccount::factory()->create();
    $a = MediaBuyer::factory()->create();
    $b = MediaBuyer::factory()->create();
    $svc = app(AssignmentService::class);
    $first = $svc->assign($acc, $a, CarbonImmutable::parse('2026-09-01'));
    expect($svc->assign($acc, $a, CarbonImmutable::parse('2026-09-12'))->id)->toBe($first->id);
    expect(AdAccountAssignment::count())->toBe(1)->and($first->fresh()->ends_on)->toBeNull();

    $svc->assign($acc, $b, CarbonImmutable::parse('2026-09-01'));
    expect(AdAccountAssignment::count())->toBe(1)->and(AdAccountAssignment::first()->media_buyer_id)->toBe($b->id);

    $svc->assign($acc, null, CarbonImmutable::parse('2026-09-01'));
    expect(AdAccountAssignment::count())->toBe(0);
});

it('scopes a buyer user to their accounts and content users to none', function () {
    $scope = app(AdsScope::class);
    $svc = app(AssignmentService::class);
    $mine = AdAccount::factory()->create();
    $past = AdAccount::factory()->create();
    $other = AdAccount::factory()->create();
    $user = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $buyer = MediaBuyer::factory()->create(['user_id' => $user->id]);
    $svc->assign($mine, $buyer, CarbonImmutable::parse('2026-09-01'));
    $svc->assign($past, $buyer, CarbonImmutable::parse('2026-08-01'));
    $svc->assign($past, null, CarbonImmutable::parse('2026-08-15'));
    $svc->assign($other, MediaBuyer::factory()->create(), CarbonImmutable::parse('2026-09-01'));

    expect($scope->accountIds($user))->toEqualCanonicalizing([$mine->id, $past->id]);
    expect($scope->accountIds($user, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30')))->toBe([$mine->id]);
    expect($scope->buyerFor($user)->id)->toBe($buyer->id)->and($scope->canSeeSpend($user))->toBeTrue();

    $content = User::factory()->create(['role' => UserRole::Content]);
    expect($scope->accountIds($content))->toBe([])->and($scope->canSeeSpend($content))->toBeFalse();

    expect($scope->accountIds(User::factory()->create(['role' => UserRole::Admin])))->toBeNull();
    expect($scope->accountIds(User::factory()->create(['role' => UserRole::Supervisor])))->toBeNull();

    $unlinked = User::factory()->create(['role' => UserRole::MediaBuyer]);
    expect($scope->accountIds($unlinked))->toBe([])->and($scope->buyerFor($unlinked))->toBeNull();
});
