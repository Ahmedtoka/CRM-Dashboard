<?php

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;

it('lets a supervisor list branches and blocks a moderator', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $mod = User::factory()->create(['role' => UserRole::Moderator]);

    $this->actingAs($mod)->get('/settings/branches')->assertForbidden();
    $this->actingAs($sup)->get('/settings/branches')->assertOk()
        ->assertInertia(fn ($p) => $p->component('settings/Branches')->has('branches', 26));
});

it('lets a supervisor create a branch and blocks a moderator', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $mod = User::factory()->create(['role' => UserRole::Moderator]);

    $payload = [
        'governorate' => 'القاهرة',
        'area_key' => 'nasr_city',
        'area_ar' => 'مدينة نصر',
        'area_en' => 'Nasr City',
        'name' => 'New Branch',
        'address' => '1 Test St.',
        'phone' => '01000000000',
        'map_url' => 'https://goo.gl/maps/test',
        'hours' => null,
        'aliases' => ['نصر جديد'],
        'is_active' => true,
        'sort' => 999,
    ];

    $this->actingAs($mod)->postJson('/settings/branches', $payload)->assertForbidden();

    $created = $this->actingAs($sup)->postJson('/settings/branches', $payload)->assertCreated()->json('data');

    $this->assertDatabaseHas('branches', ['id' => $created['id'], 'name' => 'New Branch', 'area_key' => 'nasr_city']);
});

it('lets a supervisor patch phone and is_active and blocks a moderator', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $branch = Branch::where('area_key', 'nasr_city')->firstOrFail();

    $this->actingAs($mod)->patchJson("/settings/branches/{$branch->id}", ['phone' => '01111111111'])->assertForbidden();

    $this->actingAs($sup)->patchJson("/settings/branches/{$branch->id}", [
        'phone' => '01111111111',
        'is_active' => false,
    ])->assertOk()->assertJsonPath('data.phone', '01111111111')->assertJsonPath('data.is_active', false);

    $fresh = $branch->fresh();
    expect($fresh->phone)->toBe('01111111111')->and($fresh->is_active)->toBeFalse()
        // A partial patch must not touch fields it didn't send.
        ->and($fresh->name)->toBe($branch->name);
});

it('lets a supervisor delete a branch and blocks a moderator', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $branch = Branch::where('area_key', 'zagazig')->firstOrFail();

    $this->actingAs($mod)->deleteJson("/settings/branches/{$branch->id}")->assertForbidden();
    $this->actingAs($sup)->deleteJson("/settings/branches/{$branch->id}")->assertOk();

    $this->assertDatabaseMissing('branches', ['id' => $branch->id]);
});
