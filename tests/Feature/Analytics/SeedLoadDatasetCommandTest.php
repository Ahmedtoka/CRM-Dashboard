<?php

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Message;

it('refuses outside local/staging even with a load-named database', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['database.connections.sqlite.database' => 'social_crm_load']);

    $this->artisan('crm:seed-load-dataset', ['--conversations' => 5, '--messages-per' => 2])
        ->assertFailed();

    expect(Customer::count())->toBe(0);
});

it('refuses on local/staging when the database name does not contain "load"', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['database.connections.sqlite.database' => 'social_crm']);

    $this->artisan('crm:seed-load-dataset', ['--conversations' => 5, '--messages-per' => 2])
        ->assertFailed();

    expect(Customer::count())->toBe(0);
});

it('seeds the requested dataset and prints the counts inserted when allowed', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['database.connections.sqlite.database' => 'social_crm_load']);

    $this->artisan('crm:seed-load-dataset', ['--conversations' => 5, '--messages-per' => 2])
        ->assertSuccessful()
        ->expectsOutputToContain('conversations=5')
        ->expectsOutputToContain('messages=10');

    expect(Customer::count())->toBe(5)
        ->and(CustomerIdentity::count())->toBe(5)
        ->and(Conversation::count())->toBe(5)
        ->and(Message::count())->toBe(10);
});

it('allows staging too, not only local', function () {
    app()->detectEnvironment(fn () => 'staging');
    config(['database.connections.sqlite.database' => 'social_crm_load']);

    $this->artisan('crm:seed-load-dataset', ['--conversations' => 2, '--messages-per' => 1])
        ->assertSuccessful();

    expect(Conversation::count())->toBe(2);
});

it('gives seeded customers a realistic, deterministic distribution of order flags', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['database.connections.sqlite.database' => 'social_crm_load']);

    $this->artisan('crm:seed-load-dataset', ['--conversations' => 1000, '--messages-per' => 1])
        ->assertSuccessful();

    $total = Customer::count();
    expect($total)->toBe(1000);

    $pct = fn (string $column) => Customer::where($column, true)->count() / $total * 100;

    // Plan-mandated targets: is_repeat ~30%, has_open_order ~20%, has_return ~5%,
    // has_stuck_order ~3%, within ±2 percentage points.
    expect($pct('is_repeat'))->toBeGreaterThanOrEqual(28.0)->toBeLessThanOrEqual(32.0)
        ->and($pct('has_open_order'))->toBeGreaterThanOrEqual(18.0)->toBeLessThanOrEqual(22.0)
        ->and($pct('has_return'))->toBeGreaterThanOrEqual(3.0)->toBeLessThanOrEqual(7.0)
        ->and($pct('has_stuck_order'))->toBeGreaterThanOrEqual(1.0)->toBeLessThanOrEqual(5.0);
});
