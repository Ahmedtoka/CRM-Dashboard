<?php

use App\Models\QaReview;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\SupportCase;
use App\Models\User;
use App\Queue\Reports\QueueReport;
use App\Queue\Scoring\ScoreKeeper;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

/** A closed window of `$u` on `$date`: delivered 12:00, first reply after `$reply` seconds. */
function qrWindow(User $u, string $reason, array $attrs = [], int $reply = 30, string $date = '2026-10-05'): QueueEntry
{
    $at = Carbon::parse($date.' 11:00', 'Africa/Cairo');

    return QueueEntry::factory()->create([
        'assigned_user_id' => $u->id, 'status' => 'closed', 'close_reason' => $reason, 'business_date' => $date,
        'enqueued_at' => $at, 'delivered_at' => $at, 'first_reply_at' => $at->copy()->addSeconds($reply), 'sla_met' => $reply <= 120,
        'closed_at' => $at->copy()->addMinutes(10), 'handle_seconds' => 600,
    ] + $attrs);
}

function qrSetup(): array
{
    $a = User::factory()->create(['name' => 'زينب']);
    $b = User::factory()->create(['name' => 'منار']);
    qrWindow($a, 'inquiry', ['review_stars' => 5], 30);
    qrWindow($a, 'problem', [], 90);
    $case = qrWindow($a, 'case', [], 300);
    qrWindow($b, 'auto', [], 60);
    qrWindow($b, 'no_reply', ['first_reply_at' => null, 'sla_met' => null]);
    qrWindow($b, 'inquiry', ['is_test' => true]); // never counted
    qrWindow($a, 'inquiry', [], 30, '2026-10-01'); // another day

    SupportCase::factory()->create(['opened_by_id' => $a->id, 'queue_entry_id' => $case->id, 'status' => 'resolved', 'resolved_at' => now(), 'sla_due_at' => now()->addHour()]);
    QaReview::factory()->create(['user_id' => $a->id, 'queue_entry_id' => $case->id, 'conversation_id' => $case->conversation_id, 'business_date' => '2026-10-05', 'score' => 4]);
    $k = app(ScoreKeeper::class);
    $k->award($a->id, 'base', 8, 'base_inquiry', [], 'r1', [], '2026-10-05');
    $k->award($a->id, 'speed', 6, 'speed_fast', [], 'r2', [], '2026-10-05');
    $k->award($b->id, 'no_reply', -1, 'no_reply', [], 'r3', [], '2026-10-05');
    $k->award($a->id, 'base', 8, 'base_inquiry', [], 'r4', [], '2026-10-01');

    return [$a, $b];
}

it('builds the per-moderator day: closes, times, ratings, QA, cases and points, without test chats', function () {
    [$a, $b] = qrSetup();
    $r = app(QueueReport::class)->build('2026-10-05', '2026-10-05');
    $rows = collect($r['rows'])->keyBy('user.id');

    expect($rows->keys()->all())->toBe([$a->id, $b->id])
        ->and($rows[$a->id])->toMatchArray([
            'received' => 3, 'handled' => 3, 'first_reply_avg_seconds' => 140, 'handle_avg_seconds' => 600, 'sla_pct' => 67,
            'rating_avg' => 5.0, 'rating_count' => 1, 'qa_avg' => 4.0, 'qa_count' => 1, 'cases_opened' => 1, 'cases_resolved' => 1, 'total' => 14,
        ])
        ->and($rows[$a->id]['closed'])->toMatchArray(['inquiry' => 1, 'problem' => 1, 'case' => 1])
        ->and($rows[$a->id]['points'])->toMatchArray(['base' => 8, 'speed' => 6])
        ->and($rows[$b->id])->toMatchArray(['received' => 2, 'handled' => 1, 'total' => -1])
        ->and($rows[$b->id]['closed'])->toMatchArray(['auto' => 1, 'no_reply' => 1]);

    expect($r['kpis'])->toMatchArray(['windows' => 5, 'handled' => 4, 'rating_count' => 1, 'cases_opened' => 1, 'cases_resolved' => 1, 'cases_resolved_in_time' => 1, 'points_total' => 13]);
});

it('covers a range of days', function () {
    [$a] = qrSetup();
    $row = collect(app(QueueReport::class)->build('2026-10-01', '2026-10-05')['rows'])->firstWhere('user.id', $a->id);

    expect($row['received'])->toBe(4)->and($row['total'])->toBe(22);
});

it('serves /reports/queue and its CSV to supervisors only, for at most 92 days', function () {
    [$a] = qrSetup();
    $sup = User::factory()->create(['role' => 'supervisor']);

    $props = $this->actingAs($sup)->get('/reports/queue?from=2026-10-05&to=2026-10-05')->assertOk()->viewData('page')['props'];
    expect($props['report']['rows'])->toHaveCount(2);

    $csv = $this->actingAs($sup)->get('/reports/queue/export?from=2026-10-05&to=2026-10-05')->assertOk()->streamedContent();
    expect($csv)->toStartWith("\u{FEFF}")->toContain('زينب')->and(substr_count(trim($csv), "\n"))->toBe(2);

    $this->actingAs($a)->get('/reports/queue')->assertForbidden();
    $this->actingAs($sup)->get('/reports/queue?from=2026-01-01&to=2026-10-05')->assertSessionHasErrors('to');
});
