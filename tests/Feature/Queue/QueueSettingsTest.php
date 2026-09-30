<?php

use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\User;
use App\Queue\Events\ShiftUpdated;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;

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

// ───── flow revision §7 ─────

it('keeps the defaults of the flow revision', function () {
    $s = QueueSetting::current();

    expect([$s->waiting_update_seconds, $s->agent_apology_seconds, $s->agent_reassign_first_seconds, $s->agent_reassign_seconds, $s->case_follow_owner, $s->point('no_reply')])
        ->toBe([120, 180, 300, 480, true, 1]);
});

it('lets a supervisor set the moderator-reply timers, the position update and the case switch', function () {
    $sup = User::factory()->create(['role' => 'supervisor']);

    $this->actingAs($sup)->put('/settings/queue', [
        'waiting_update_seconds' => 90, 'agent_apology_seconds' => 150,
        'agent_reassign_first_seconds' => 240, 'agent_reassign_seconds' => 600, 'case_follow_owner' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $s = QueueSetting::current();
    expect([$s->waiting_update_seconds, $s->agent_apology_seconds, $s->agent_reassign_first_seconds, $s->agent_reassign_seconds, $s->case_follow_owner])
        ->toBe([90, 150, 240, 600, false]);
});

it('rejects an apology that is not before both hand-offs, and timers out of range', function (array $body, string $field) {
    $this->actingAs(User::factory()->create(['role' => 'supervisor']))->put('/settings/queue', $body)->assertSessionHasErrors($field);
})->with([
    'apology at the first hand-off' => [['agent_apology_seconds' => 300, 'agent_reassign_first_seconds' => 300], 'agent_apology_seconds'],
    'apology after the later hand-off' => [['agent_apology_seconds' => 400, 'agent_reassign_first_seconds' => 600, 'agent_reassign_seconds' => 360], 'agent_apology_seconds'],
    'first hand-off too short' => [['agent_reassign_first_seconds' => 119], 'agent_reassign_first_seconds'],
    'later hand-off too long' => [['agent_reassign_seconds' => 3601], 'agent_reassign_seconds'],
    'update too often' => [['waiting_update_seconds' => 29], 'waiting_update_seconds'],
    'update too rarely' => [['waiting_update_seconds' => 901], 'waiting_update_seconds'],
    'a break of no minutes' => [['break_minutes' => 0], 'break_minutes'],
]);

it('lets only an admin change the no-reply points, and shows the default on a row saved before it existed', function () {
    QueueSetting::current()->update(['points' => ['inquiry' => 8]]);   // a row saved before `no_reply`
    $sup = User::factory()->create(['role' => 'supervisor']);

    $this->actingAs($sup)->put('/settings/queue', ['points' => ['no_reply' => 2]])->assertForbidden();
    $this->actingAs($sup)->get('/settings/queue')
        ->assertInertia(fn (AssertableInertia $page) => $page->component('settings/Queue')->where('settings.points.no_reply', 1));

    $this->actingAs(User::factory()->create(['role' => 'admin']))->put('/settings/queue', ['points' => ['no_reply' => 2]])->assertRedirect();
    expect(QueueSetting::current()->point('no_reply'))->toBe(2);
});

// ───── attendance design §2: the leader is the template's ─────

it('gives a shift that is not closed its new leader as soon as the templates are saved', function () {
    $old = User::factory()->create(['role' => 'supervisor']);
    $new = User::factory()->create(['role' => 'supervisor']);
    $open = Shift::factory()->create(['leader_user_id' => $old->id]);
    $closed = Shift::factory()->create(['status' => 'closed', 'date' => now()->subDay()->toDateString(), 'leader_user_id' => $old->id]);
    $escalation = QueueEntry::factory()->create(['priority' => 'escalation', 'shift_id' => $open->id, 'reserved_user_id' => $old->id]);
    Event::fake([ShiftUpdated::class]);

    $templates = QueueSetting::DEFAULT_SHIFTS;
    $templates[0]['leader_user_id'] = $new->id;
    $this->actingAs(User::factory()->create(['role' => 'admin']))->put('/settings/queue', ['shifts' => $templates])->assertRedirect()->assertSessionHasNoErrors();

    expect($open->fresh()->leader_user_id)->toBe($new->id)
        ->and($closed->fresh()->leader_user_id)->toBe($old->id)
        ->and($escalation->fresh()->reserved_user_id)->toBe($new->id);
    Event::assertDispatched(ShiftUpdated::class, fn (ShiftUpdated $e) => $e->shift->id === $open->id);
});
