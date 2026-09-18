<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates an active admin with a prompted password', function () {
    $this->artisan('crm:create-admin', ['email' => 'Owner@LeVoile.com', '--name' => 'Owner'])
        ->expectsQuestion('Password (min 8 characters)', 'secret-123')
        ->expectsQuestion('Repeat password', 'secret-123')
        ->assertSuccessful();

    $user = User::where('email', 'owner@levoile.com')->firstOrFail();
    expect($user->role)->toBe(UserRole::Admin)
        ->and($user->is_active)->toBeTrue()
        ->and($user->name)->toBe('Owner')
        ->and(Hash::check('secret-123', $user->password))->toBeTrue();
});

it('promotes an existing user instead of duplicating', function () {
    $existing = User::factory()->create(['email' => 'a@b.com', 'role' => UserRole::Moderator, 'is_active' => false, 'name' => 'Mona']);

    $this->artisan('crm:create-admin', ['email' => 'a@b.com'])
        ->expectsQuestion('Password (min 8 characters)', 'another-pass')
        ->expectsQuestion('Repeat password', 'another-pass')
        ->assertSuccessful();

    $existing->refresh();
    expect(User::where('email', 'a@b.com')->count())->toBe(1)
        ->and($existing->role)->toBe(UserRole::Admin)
        ->and($existing->is_active)->toBeTrue()
        ->and($existing->name)->toBe('Mona');
});

it('refuses mismatched or short passwords', function () {
    $this->artisan('crm:create-admin', ['email' => 'x@y.com'])
        ->expectsQuestion('Password (min 8 characters)', 'short')
        ->expectsQuestion('Repeat password', 'short')
        ->assertFailed();

    expect(User::where('email', 'x@y.com')->exists())->toBeFalse();
});
