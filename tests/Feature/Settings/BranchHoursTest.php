<?php

use App\Analytics\ActivityLogger;
use App\Bot\Flows\BranchFinder;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\User;

// Audit wave 1 (2026-10-16): «مواعيد الفروع» — admins edit only a branch's hours, phone and
// active flag; branches are not created, deleted or re-areaed here (the full page stays removed).

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
});

it('shows the branches to an admin only', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $mod = User::factory()->create(['role' => UserRole::Moderator]);

    $this->get('/settings/branch-hours')->assertRedirect('/login');
    $this->actingAs($mod)->get('/settings/branch-hours')->assertForbidden();
    $this->actingAs($sup)->get('/settings/branch-hours')->assertForbidden();

    $count = Branch::count();
    $this->actingAs($this->admin)->get('/settings/branch-hours')->assertOk()
        ->assertInertia(fn ($p) => $p->component('settings/BranchHours')
            ->has('branches', $count)
            ->has('branches.0', fn ($b) => $b->hasAll(['id', 'name', 'area_ar', 'area_en', 'hours', 'phone', 'is_active'])));
});

it('lists branches in the bot order (sort, then id)', function () {
    Branch::query()->delete();
    $late = Branch::factory()->create(['name' => 'Late', 'sort' => 20]);
    $early = Branch::factory()->create(['name' => 'Early', 'sort' => 10]);

    $this->actingAs($this->admin)->get('/settings/branch-hours')
        ->assertInertia(fn ($p) => $p->where('branches.0.id', $early->id)->where('branches.1.id', $late->id));
});

it('blocks a supervisor from updating a branch', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $branch = Branch::factory()->create();

    $this->actingAs($sup)->putJson("/settings/branch-hours/{$branch->id}", ['hours' => '10 ص - 10 م'])->assertForbidden();
    expect($branch->fresh()->hours)->toBeNull();
});

it('updates hours, phone and active, and the bot reads the change', function () {
    Branch::query()->delete();
    $branch = Branch::factory()->create(['area_key' => 'maadi', 'area_ar' => 'المعادي', 'name' => 'Degla', 'phone' => '01000000000', 'hours' => null]);

    $this->actingAs($this->admin)->putJson("/settings/branch-hours/{$branch->id}", [
        'hours' => '  يوميًا من ١٠ ص لـ ١١ م  ',
        'phone' => '01111111111',
    ])->assertOk()
        ->assertJsonPath('data.hours', 'يوميًا من ١٠ ص لـ ١١ م')
        ->assertJsonPath('data.phone', '01111111111');

    $finder = app(BranchFinder::class);
    expect($finder->listText('maadi'))->toContain('يوميًا من ١٠ ص لـ ١١ م')->toContain('01111111111');

    $this->actingAs($this->admin)->putJson("/settings/branch-hours/{$branch->id}", ['is_active' => false])
        ->assertOk()->assertJsonPath('data.is_active', false);

    expect($finder->listText('maadi'))->toBe('')
        ->and(collect($finder->areas())->pluck('key')->all())->not->toContain('maadi');
});

it('clears hours and phone when sent empty', function () {
    $branch = Branch::factory()->create(['hours' => '10-10', 'phone' => '01000000000']);

    $this->actingAs($this->admin)->putJson("/settings/branch-hours/{$branch->id}", ['hours' => '', 'phone' => ''])->assertOk();

    expect($branch->fresh())->hours->toBeNull()->phone->toBeNull();
});

it('never changes the name, area or address', function () {
    $branch = Branch::factory()->create(['name' => 'Degla', 'area_key' => 'maadi', 'area_ar' => 'المعادي', 'address' => '1 St.']);

    $this->actingAs($this->admin)->putJson("/settings/branch-hours/{$branch->id}", [
        'hours' => '10-10', 'name' => 'Other', 'area_key' => 'nasr_city', 'area_ar' => 'x', 'address' => 'y', 'sort' => 1,
    ])->assertOk();

    expect($branch->fresh())
        ->name->toBe('Degla')->area_key->toBe('maadi')->area_ar->toBe('المعادي')->address->toBe('1 St.')->hours->toBe('10-10');
});

it('validates the input', function (array $payload, string $field) {
    $branch = Branch::factory()->create();

    $this->actingAs($this->admin)->putJson("/settings/branch-hours/{$branch->id}", $payload)->assertJsonValidationErrors($field);
})->with([
    'hours too long' => [['hours' => str_repeat('a', 201)], 'hours'],
    'hours not text' => [['hours' => ['x']], 'hours'],
    'phone with letters' => [['phone' => 'call us'], 'phone'],
    'phone too long' => [['phone' => str_repeat('1', 31)], 'phone'],
    'active not boolean' => [['is_active' => 'maybe'], 'is_active'],
]);

it('accepts Arabic digits and spaces in the phone', function () {
    $branch = Branch::factory()->create();

    $this->actingAs($this->admin)->putJson("/settings/branch-hours/{$branch->id}", ['phone' => '٠١٠ ٩٤٥٣ ٨١٥٩'])->assertOk();

    expect($branch->fresh()->phone)->toBe('٠١٠ ٩٤٥٣ ٨١٥٩');
});

it('logs what changed', function () {
    $branch = Branch::factory()->create(['name' => 'Degla', 'hours' => null, 'phone' => '01000000000', 'is_active' => true]);

    $this->actingAs($this->admin)->putJson("/settings/branch-hours/{$branch->id}", ['hours' => '10-10', 'phone' => '01000000000'])->assertOk();

    $log = ActivityLog::query()->where('action', ActivityLogger::BRANCH_UPDATED)->sole();
    expect($log->user_id)->toBe($this->admin->id)
        ->and($log->subject_id)->toBe($branch->id)
        ->and($log->meta)->toBe(['name' => 'Degla', 'changed' => ['hours']]);
});

it('logs nothing when nothing changed', function () {
    $branch = Branch::factory()->create(['hours' => '10-10']);

    $this->actingAs($this->admin)->putJson("/settings/branch-hours/{$branch->id}", ['hours' => '10-10'])->assertOk();

    expect(ActivityLog::query()->where('action', ActivityLogger::BRANCH_UPDATED)->exists())->toBeFalse();
});

it('has no create or delete route', function () {
    $branch = Branch::factory()->create();

    $this->actingAs($this->admin)->postJson('/settings/branch-hours', ['name' => 'x'])->assertStatus(405);
    $this->actingAs($this->admin)->deleteJson("/settings/branch-hours/{$branch->id}")->assertStatus(405);
    expect($branch->fresh())->not->toBeNull();
});
