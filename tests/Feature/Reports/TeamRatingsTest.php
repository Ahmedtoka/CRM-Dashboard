<?php

use App\Enums\UserRole;
use App\Models\QueueEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-06 12:00', 'Africa/Cairo')));

function trRated(User $u, int $stars, Carbon $at): QueueEntry
{
    $at = $at->copy()->utc();

    return QueueEntry::factory()->create(['assigned_user_id' => $u->id, 'status' => 'closed', 'close_reason' => 'inquiry', 'closed_at' => $at, 'review_stars' => $stars, 'reviewed_at' => $at]);
}

it('shows the ratings per agent per day and the answers, the low ones alone with stars=low', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $mona = User::factory()->create(['role' => UserRole::Moderator, 'name' => 'Mona']);
    $low = trRated($mona, 1, now()->subHour());
    trRated($mona, 5, now()->subMinutes(10));
    trRated($mona, 4, now()->subDay()); // outside today's range

    $this->actingAs($sup)->get('/reports/team?from=2026-10-06&to=2026-10-06')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('Reports/Team')
            ->where('ratings.stars', 'all')
            ->where('ratings.summary', ['count' => 2, 'avg' => 3, 'low' => 1]) // 3.0 travels as 3 in JSON
            ->where('ratings.by_agent_day.0.user.name', 'Mona')
            ->where('ratings.by_agent_day.0.date', '2026-10-06')
            ->where('ratings.by_agent_day.0.count', 2)
            ->has('ratings.list', 2));

    $this->actingAs($sup)->get('/reports/team?from=2026-10-06&to=2026-10-06&stars=low')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('ratings.stars', 'low')->has('ratings.list', 1)
            ->where('ratings.list.0.entry_id', $low->id)
            ->where('ratings.list.0.conversation_id', $low->conversation_id)
            ->where('ratings.list.0.customer', $low->conversation->customer->name)
            ->where('ratings.list.0.user.name', 'Mona'));
});

it('keeps the team report closed to agents and the api payload unchanged', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $this->actingAs($mod)->get('/reports/team')->assertForbidden();
    auth()->forgetGuards(); // Sanctum checks the web guard first; drop the agent signed in above

    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $token = $sup->createToken('t')->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/reports/team')->assertOk()->assertJsonMissingPath('ratings');
});
