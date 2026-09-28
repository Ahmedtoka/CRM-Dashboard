<?php

use App\Models\{QueueSetting, User};

it('lets a supervisor change timers but only an admin change points and shifts', function () {
    $sup = User::factory()->create(['role' => 'supervisor']);
    $this->actingAs($sup)->put('/settings/queue', ['silence_close_seconds' => 420, 'silence_warn_seconds' => 120])->assertRedirect();
    expect(QueueSetting::current()->silence_close_seconds)->toBe(420);
    $this->actingAs($sup)->put('/settings/queue', ['points' => ['inquiry' => 9]])->assertForbidden();
    $admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($admin)->put('/settings/queue', ['points' => ['inquiry' => 9], 'shifts' => [['key' => 'morning', 'name' => 'صباحي', 'from' => '10:00', 'to' => '18:00', 'location' => 'office', 'leader_user_id' => $sup->id]]])->assertRedirect();
    expect(QueueSetting::current()->point('inquiry'))->toBe(9)->and(QueueSetting::current()->shifts)->toHaveCount(1);
});

it('rejects a warn time above the close time and a moderator entirely', function () {
    $sup = User::factory()->create(['role' => 'supervisor']);
    $this->actingAs($sup)->put('/settings/queue', ['silence_warn_seconds' => 400, 'silence_close_seconds' => 300])->assertSessionHasErrors('silence_warn_seconds');
    $this->actingAs(User::factory()->create(['role' => 'moderator']))->get('/settings/queue')->assertForbidden();
});
