<?php

use App\Bot\Flows\BranchFinder;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;

// Fresh-orders F4: the branches settings page is gone; the bot's store-branch data (BranchFinder) stays.
it('has no branches settings page any more, while the bot still reads its branches', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    Branch::factory()->create(['area_key' => 'nasr', 'area_ar' => 'مدينة نصر', 'is_active' => true]);

    $this->actingAs($sup)->get('/settings/branches')->assertNotFound();
    $this->actingAs($sup)->postJson('/settings/branches', [])->assertNotFound();

    expect(collect(app(BranchFinder::class)->areas())->pluck('key')->all())->toContain('nasr');
});
