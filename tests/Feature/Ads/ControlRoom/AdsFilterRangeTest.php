<?php

require_once __DIR__.'/../../../Support/AdsControlRoom.php';

use App\Ads\Reports\AdsFilter;
use App\Enums\UserRole;
use App\Models\MediaBuyer;
use Illuminate\Http\Request;

beforeEach(fn () => crSetup($this));

function rangeFilter(array $q, string $default = 'last30', ?App\Models\User $u = null): AdsFilter
{
    return AdsFilter::fromRequest(Request::create('/ads', 'GET', $q), $u ?? crAdmin(), $default);
}

it('uses the page default range when the URL has none', function () {
    $f = rangeFilter([], 'last7');
    expect($f->fromDate())->toBe('2026-09-29')->and($f->toDate())->toBe('2026-10-05')->and($f->rangeKey())->toBe('last7');
});

it('keeps the 30-day default (ending today) for callers that pass no default', function () {
    $f = rangeFilter([]);
    expect($f->fromDate())->toBe('2026-09-07')->and($f->toDate())->toBe('2026-10-06')->and($f->rangeKey())->toBe('last30');
});

it('lets a range preset win over from and to', function () {
    $f = rangeFilter(['range' => 'this_month', 'from' => '2026-09-01', 'to' => '2026-09-03']);
    expect($f->fromDate())->toBe('2026-10-01')->and($f->toDate())->toBe('2026-10-06')->and($f->rangeKey())->toBe('this_month');
});

it('reads explicit dates and names no preset for them', function () {
    $f = rangeFilter(['from' => '2026-09-10', 'to' => '2026-09-12'], 'last7');
    expect($f->fromDate())->toBe('2026-09-10')->and($f->toDate())->toBe('2026-09-12')->and($f->rangeKey())->toBeNull();
});

it('reads today and yesterday presets', function () {
    expect(rangeFilter(['range' => 'today'])->fromDate())->toBe('2026-10-06')
        ->and(rangeFilter(['range' => 'yesterday'])->toDate())->toBe('2026-10-05');
});

it('reads accounts as an array or as a comma list', function () {
    expect(rangeFilter(['accounts' => '3,5,x'])->accountIds)->toBe([3, 5])
        ->and(rangeFilter(['accounts' => ['7', 'y', '7']])->accountIds)->toBe([7]);
});

it('resolves buyer=me to the supervisor own buyer row, and to nothing when there is none', function () {
    $sup = crUser(UserRole::Supervisor);
    $b = MediaBuyer::factory()->create(['user_id' => $sup->id]);
    expect(rangeFilter(['buyer' => 'me'], 'last7', $sup)->buyerId)->toBe($b->id)
        ->and(rangeFilter(['buyer' => 'me'], 'last7', crAdmin())->buyerId)->toBe(0);
});
