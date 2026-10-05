<?php

use App\Models\AdAccount;
use App\Models\AdWriteAction;
use App\Models\AdWriteStep;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

function wamMigration(string $file): object
{
    return require database_path('migrations/'.$file);
}

it('gives every action a 26-char ULID public id and routes by it', function () {
    $a = AdWriteAction::factory()->proposed()->create();

    expect($a->public_id)->toBeString()->toHaveLength(26)
        ->and($a->getRouteKeyName())->toBe('public_id')
        ->and($a->state)->toBe(AdWriteAction::PROPOSED);
});

it('refuses two actions with the same proposer and idempotency key', function () {
    $u = User::factory()->create();
    AdWriteAction::factory()->create(['proposed_by_id' => $u->id, 'idempotency_key' => 'k-1']);

    expect(fn () => AdWriteAction::factory()->create(['proposed_by_id' => $u->id, 'idempotency_key' => 'k-1']))
        ->toThrow(QueryException::class);
});

it('lets the same idempotency key be used by two different proposers', function () {
    AdWriteAction::factory()->create(['proposed_by_id' => User::factory(), 'idempotency_key' => 'k-1']);
    AdWriteAction::factory()->create(['proposed_by_id' => User::factory(), 'idempotency_key' => 'k-1']);

    expect(AdWriteAction::count())->toBe(2);
});

it('lets many actions coexist with a NULL open business key', function () {
    AdWriteAction::factory()->stop()->count(3)->create(['open_business_key' => null]);

    expect(AdWriteAction::whereNull('open_business_key')->count())->toBe(3);
});

it('refuses two actions holding the same open business key', function () {
    AdWriteAction::factory()->run()->create(['open_business_key' => 'run:1:ad:123']);

    expect(fn () => AdWriteAction::factory()->run()->create(['open_business_key' => 'run:1:ad:123']))
        ->toThrow(QueryException::class);
});

it('never deletes an action (history is kept)', function () {
    $a = AdWriteAction::factory()->succeeded()->create();

    expect(fn () => $a->delete())->toThrow(LogicException::class)
        ->and(AdWriteAction::count())->toBe(1);
});

it('returns steps ordered by seq and links them back', function () {
    $a = AdWriteAction::factory()->create();
    foreach ([3, 1, 2] as $seq) {
        AdWriteStep::create(['ad_write_action_id' => $a->id, 'seq' => $seq, 'op' => 'set_status', 'level' => 'ad', 'target_external_id' => '9', 'state' => AdWriteStep::PENDING, 'before' => ['status' => 'ACTIVE']]);
    }

    $steps = $a->fresh()->steps;
    expect($steps->pluck('seq')->all())->toBe([1, 2, 3])
        ->and($steps->first()->before)->toBe(['status' => 'ACTIVE'])
        ->and($steps->first()->action->is($a))->toBeTrue();
});

it('refuses two steps with the same seq on one action', function () {
    $a = AdWriteAction::factory()->create();
    AdWriteStep::create(['ad_write_action_id' => $a->id, 'seq' => 1, 'op' => 'set_status', 'level' => 'ad', 'target_external_id' => '9', 'state' => 'pending']);

    expect(fn () => AdWriteStep::create(['ad_write_action_id' => $a->id, 'seq' => 1, 'op' => 'set_status', 'level' => 'ad', 'target_external_id' => '9', 'state' => 'pending']))
        ->toThrow(QueryException::class);
});

it('casts json and timestamps and knows a Stop', function () {
    $acc = AdAccount::factory()->create();
    $a = AdWriteAction::factory()->stop()->create(['ad_account_id' => $acc->id, 'diff' => ['status' => ['from' => 'ACTIVE', 'to' => 'PAUSED']], 'expires_at' => now()->addMinutes(10)]);
    $a = $a->fresh();

    expect($a->isStop())->toBeTrue()
        ->and($a->diff)->toBe(['status' => ['from' => 'ACTIVE', 'to' => 'PAUSED']])
        ->and($a->expires_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($a->account->is($acc))->toBeTrue()
        ->and(AdWriteAction::factory()->run()->make()->isStop())->toBeFalse();
});

it('lists the terminal and open states', function () {
    expect(AdWriteAction::TERMINAL)->toContain(AdWriteAction::SUCCEEDED, AdWriteAction::SUPERSEDED_BY_STOP, AdWriteAction::ROLLED_BACK)
        ->not->toContain(AdWriteAction::EXECUTING, AdWriteAction::UNKNOWN, AdWriteAction::PROPOSED)
        ->and(AdWriteAction::OPEN)->toBe([AdWriteAction::EXECUTING, AdWriteAction::UNKNOWN]);
});

it('runs the migrations twice without error and survives down then up', function () {
    $actions = wamMigration('2026_10_07_100010_create_ad_write_actions_table.php');
    $steps = wamMigration('2026_10_07_100020_create_ad_write_steps_table.php');

    $actions->up();
    $steps->up(); // guarded: tables already exist

    $steps->down();
    $actions->down();
    expect(Schema::hasTable('ad_write_actions'))->toBeFalse()->and(Schema::hasTable('ad_write_steps'))->toBeFalse();

    $actions->up();
    $steps->up();
    expect(Schema::hasTable('ad_write_actions'))->toBeTrue()
        ->and(Schema::hasColumns('ad_write_actions', ['public_id', 'open_business_key', 'target_key', 'diff_hash', 'restart_lock_until']))->toBeTrue()
        ->and(Schema::hasColumns('ad_write_steps', ['ad_write_action_id', 'seq', 'platform_trace']))->toBeTrue();

    AdWriteAction::factory()->create();
    expect(AdWriteAction::count())->toBe(1);
});
