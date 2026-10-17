<?php

use App\Models\QaReview;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\ScoreEvent;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use App\Queue\Scoring\ScoreKeeper;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

/** A moderator at a desk of the open shift with one closed window of today, already paid. */
function ddDesk(): array
{
    $shift = Shift::factory()->create(['status' => 'open', 'opened_at' => now()->subHours(2), 'starts_at' => now()->subHours(2), 'ends_at' => now()->addHours(6)]);
    $u = User::factory()->create(['last_seen_at' => now()]);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'available']);
    $e = QueueEntry::factory()->create([
        'shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $u->id, 'status' => 'closed', 'close_reason' => 'problem',
        'delivered_at' => now()->subMinutes(20), 'first_reply_at' => now()->subMinutes(19), 'closed_at' => now()->subMinutes(5), 'handle_seconds' => 900, 'review_stars' => 5,
    ]);
    app(ScoreKeeper::class)->award($u->id, 'base', 12, 'base_problem', ['ticket' => $e->ticket_no], 'base:'.$e->id, ['queue_entry_id' => $e->id, 'conversation_id' => $e->conversation_id]);

    return [$m, $u, $e, $shift];
}

function ddSupervisor(): User
{
    return User::factory()->create(['role' => 'supervisor']);
}

it('drills into one desk: her points, ledger and the windows she closed today', function () {
    [$m, $u, $e] = ddDesk();
    QueueEntry::factory()->create(['assigned_user_id' => $u->id, 'status' => 'closed', 'close_reason' => 'inquiry', 'is_test' => true]);

    $this->actingAs(ddSupervisor())->getJson("/board/members/{$m->id}/day")->assertOk()
        ->assertJsonPath('data.user.id', $u->id)
        ->assertJsonPath('data.points.total', 12)
        ->assertJsonPath('data.points.by_kind.base', 12)
        ->assertJsonPath('data.points.ledger.0.kind', 'base')
        ->assertJsonCount(1, 'data.windows')
        ->assertJsonPath('data.windows.0.id', $e->id)
        ->assertJsonPath('data.windows.0.conversation_id', $e->conversation_id)
        ->assertJsonPath('data.windows.0.first_reply_seconds', 60)
        ->assertJsonPath('data.windows.0.review_stars', 5)
        ->assertJsonPath('data.windows.0.can_qa', true)
        ->assertJsonPath('data.windows.0.qa', null);
});

it('keeps the desk drill-in to board users and shows points on the desks', function () {
    [$m, $u] = ddDesk();
    $this->actingAs(User::factory()->create())->getJson("/board/members/{$m->id}/day")->assertForbidden();

    $desk = collect($this->actingAs(ddSupervisor())->getJson('/board/state')->json('data.members'))->firstWhere('id', $m->id);
    expect($desk['points_today'])->toBe(12);
});

it('lets the leader score a closed window: one review per window, a re-score replaces the points', function () {
    [$m, $u, $e] = ddDesk();
    $sup = ddSupervisor();

    $this->actingAs($sup)->postJson("/board/entries/{$e->id}/qa", ['score' => 5, 'checklist' => ['introduced' => true, 'tone' => false], 'notes' => 'ممتازة'])
        ->assertOk()->assertJsonPath('data.windows.0.qa.score', 5)->assertJsonPath('data.points.by_kind.qa', 10);
    $this->actingAs($sup)->postJson("/board/entries/{$e->id}/qa", ['score' => 2])->assertOk()->assertJsonPath('data.points.by_kind.qa', -5);

    expect(QaReview::count())->toBe(1)
        ->and(QaReview::first()->reviewer_id)->toBe($sup->id)
        ->and(QaReview::first()->checklist)->toMatchArray(['introduced' => true, 'tone' => false, 'accurate' => false])
        ->and(ScoreEvent::query()->where('kind', 'qa')->orderBy('id')->pluck('points')->all())->toBe([10, -10, -5]);
});

it('refuses QA on her own window, on a test or open window, and on an old day', function () {
    [$m, $u, $e] = ddDesk();
    $sup = ddSupervisor();
    $own = QueueEntry::factory()->create(['assigned_user_id' => $sup->id, 'status' => 'closed', 'close_reason' => 'inquiry']);
    $test = QueueEntry::factory()->create(['assigned_user_id' => $u->id, 'status' => 'closed', 'close_reason' => 'inquiry', 'is_test' => true]);
    $open = QueueEntry::factory()->create(['assigned_user_id' => $u->id, 'status' => 'active']);
    $old = QueueEntry::factory()->create(['assigned_user_id' => $u->id, 'status' => 'closed', 'close_reason' => 'inquiry', 'business_date' => '2026-10-01']);

    foreach ([$own, $test, $open, $old] as $x) {
        $this->actingAs($sup)->postJson("/board/entries/{$x->id}/qa", ['score' => 4])->assertStatus(422);
    }
    $this->actingAs($sup)->postJson("/board/entries/{$e->id}/qa", ['score' => 9])->assertStatus(422);
    expect(QaReview::count())->toBe(0);
});

it('shows a moderator only her own points', function () {
    [$m, $u] = ddDesk();
    $other = User::factory()->create();

    $this->actingAs($u)->getJson('/queue/me/points')->assertOk()->assertJsonPath('data.points.total', 12)->assertJsonPath('data.user.id', $u->id);
    $this->actingAs($other)->getJson('/queue/me/points')->assertOk()->assertJsonPath('data.points.total', 0);
    $this->actingAs($u)->getJson('/queue/me')->assertOk()->assertJsonPath('data.points_today.total', 12);
});

it('puts her own points of the report range on /reports/me', function () {
    [$m, $u] = ddDesk();

    $props = $this->actingAs($u)->get('/reports/me?from=2026-10-05&to=2026-10-05')->assertOk()->viewData('page')['props'];
    expect($props['queue_points']['total'])->toBe(12)
        ->and($props['queue_points']['ledger'][0]['kind'])->toBe('base');
});

it('pays a saved score whose points were lost, when the same score is saved again', function () {
    [$m, $u, $e] = ddDesk();
    $sup = ddSupervisor();
    QaReview::factory()->create(['user_id' => $u->id, 'reviewer_id' => $sup->id, 'queue_entry_id' => $e->id, 'conversation_id' => $e->conversation_id, 'score' => 5]);

    $this->actingAs($sup)->postJson("/board/entries/{$e->id}/qa", ['score' => 5])->assertOk()->assertJsonPath('data.points.by_kind.qa', 10);
    $this->actingAs($sup)->postJson("/board/entries/{$e->id}/qa", ['score' => 5])->assertOk()->assertJsonPath('data.points.by_kind.qa', 10);

    expect(ScoreEvent::query()->where('kind', 'qa')->count())->toBe(1);
});
