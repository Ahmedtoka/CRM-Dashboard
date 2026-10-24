<?php

use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\SupportCase;
use App\Models\User;
use App\Queue\Reports\TeamReport;
use App\Queue\Scoring\ScoreKeeper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

/** A closed window of `$u` on `$date`: delivered 11:00, first reply after `$reply` seconds (null = never replied). */
function trWindow(User $u, string $reason, array $attrs = [], ?int $reply = 30, string $date = '2026-10-05'): QueueEntry
{
    $at = Carbon::parse($date.' 11:00', 'Africa/Cairo');

    return QueueEntry::factory()->create([
        'assigned_user_id' => $u->id, 'status' => 'closed', 'close_reason' => $reason, 'business_date' => $date,
        'enqueued_at' => $at, 'delivered_at' => $at, 'first_reply_at' => $reply === null ? null : $at->copy()->addSeconds($reply),
        'closed_at' => $at->copy()->addMinutes(10), 'handle_seconds' => 600,
    ] + $attrs);
}

/** The outcome recorded for a window's episode (the key is whatever the recorder wrote — not an enum here). */
function trOutcome(QueueEntry $e, string $outcome): void
{
    DB::table('conversation_outcomes')->insert([
        'conversation_id' => $e->conversation_id, 'episode_key' => 'e'.$e->id, 'queue_entry_id' => $e->id, 'outcome' => $outcome,
        'source' => 'agent', 'set_at' => now(), 'ended_at' => now(), 'reached_agent' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @return array{0: User, 1: User} */
function trSetup(): array
{
    $a = User::factory()->create(['name' => 'زينب']);
    $b = User::factory()->create(['name' => 'منار']);

    // زينب: five windows — three replies count for the median (30, 90, 300 → 90), a claim is left out, a transfer is a hand-off.
    $w1 = trWindow($a, 'inquiry', ['review_stars' => 5], 30);
    $w2 = trWindow($a, 'problem', ['review_stars' => 4], 90);
    $w3 = trWindow($a, 'case', [], 300);
    trWindow($a, 'transfer', [], null);
    trWindow($a, 'inquiry', ['rule' => 'claim'], 10);

    // منار: an auto-close, a no-reply hand-off and one handled inquiry; two replies (40, 100 → 70).
    $w6 = trWindow($b, 'auto', [], 40);
    trWindow($b, 'no_reply', [], null);
    $w8 = trWindow($b, 'inquiry', [], 100);

    $test = trWindow($b, 'inquiry', ['is_test' => true], 5); // never counted
    $otherDay = trWindow($a, 'auto', [], 5, '2026-10-01'); // another day

    trOutcome($w1, 'ordered');
    trOutcome($w2, 'price');
    trOutcome($w6, 'ordered');
    trOutcome($w8, 'zzz_new_key'); // a key no translation knows yet
    trOutcome($test, 'ordered');
    trOutcome($otherDay, 'ordered');

    // Open cases, live: w3's case pays زينب; a flow case opened by منار and assigned to زينب counts for both;
    // a resolved case and a case on a test window never count.
    SupportCase::factory()->create(['opened_by_id' => $a->id, 'queue_entry_id' => $w3->id, 'status' => 'new']);
    SupportCase::factory()->create(['opened_by_id' => $b->id, 'assigned_to_id' => $a->id, 'status' => 'in_progress']);
    SupportCase::factory()->create(['opened_by_id' => $a->id, 'queue_entry_id' => $w1->id, 'status' => 'resolved', 'resolved_at' => now()]);
    SupportCase::factory()->create(['opened_by_id' => $b->id, 'queue_entry_id' => $test->id, 'status' => 'new']);

    $k = app(ScoreKeeper::class);
    $k->award($a->id, 'base', 8, 'base_inquiry', [], 'r1', [], '2026-10-05');
    $k->award($a->id, 'speed', 6, 'speed_fast', [], 'r2', [], '2026-10-05');
    $k->award($b->id, 'no_reply', -1, 'no_reply', [], 'r3', [], '2026-10-05');
    $k->award($a->id, 'base', 8, 'base_inquiry', [], 'r4', [], '2026-10-01');

    return [$a, $b];
}

it('builds one row per moderator: windows, median first reply, auto-closes, hand-offs, ratings, points, live open cases and outcomes by key', function () {
    [$a, $b] = trSetup();
    $r = app(TeamReport::class)->build('2026-10-05', '2026-10-05');
    $rows = collect($r['rows'])->keyBy('user.id');

    expect($r)->toMatchArray(['from' => '2026-10-05', 'to' => '2026-10-05', 'outcome_keys' => ['ordered', 'price', 'zzz_new_key']])
        ->and($rows->keys()->all())->toBe([$a->id, $b->id])
        ->and($rows[$a->id])->toMatchArray([
            'received' => 5, 'handled' => 4, 'first_reply_median_seconds' => 90, 'auto_closes' => 0,
            'hand_offs' => 1, 'hand_offs_no_reply' => 0, 'hand_offs_transfer' => 1,
            'rating_avg' => 4.5, 'rating_count' => 2, 'points' => 14, 'open_cases' => 2,
            'outcomes' => ['ordered' => 1, 'price' => 1, 'zzz_new_key' => 0], // every key of the range, so the columns line up
        ])
        ->and($rows[$a->id]['user'])->toMatchArray(['id' => $a->id, 'name' => 'زينب'])
        ->and($rows[$b->id])->toMatchArray([
            'received' => 3, 'handled' => 2, 'first_reply_median_seconds' => 70, 'auto_closes' => 1,
            'hand_offs' => 1, 'hand_offs_no_reply' => 1, 'hand_offs_transfer' => 0,
            'rating_avg' => null, 'rating_count' => 0, 'points' => -1, 'open_cases' => 1,
            'outcomes' => ['ordered' => 1, 'price' => 0, 'zzz_new_key' => 1],
        ]);

    // The team: the median runs over every counted window (30, 40, 90, 100, 300 → 90); open cases are distinct cases.
    expect($r['totals'])->toMatchArray([
        'received' => 8, 'handled' => 6, 'first_reply_median_seconds' => 90, 'auto_closes' => 1,
        'hand_offs' => 2, 'hand_offs_no_reply' => 1, 'hand_offs_transfer' => 1,
        'rating_avg' => 4.5, 'rating_count' => 2, 'points' => 13, 'open_cases' => 2,
        'outcomes' => ['ordered' => 2, 'price' => 1, 'zzz_new_key' => 1],
    ]);
});

it('covers a range of days and takes the median of an even count', function () {
    [$a] = trSetup();
    $row = collect(app(TeamReport::class)->build('2026-10-01', '2026-10-05')['rows'])->firstWhere('user.id', $a->id);

    // Replies 5, 30, 90, 300 → (30 + 90) / 2 = 60; the other day's auto-close and points join in.
    expect($row)->toMatchArray(['received' => 6, 'handled' => 5, 'first_reply_median_seconds' => 60, 'auto_closes' => 1, 'points' => 22])
        ->and($row['outcomes'])->toBe(['ordered' => 2, 'price' => 1, 'zzz_new_key' => 0]);
});

it('lists a moderator who only has points or an open case in the range', function () {
    $c = User::factory()->create(['name' => 'هدى']);
    app(ScoreKeeper::class)->award($c->id, 'base', 3, 'base_inquiry', [], 'r9', [], '2026-10-05');

    $rows = collect(app(TeamReport::class)->build('2026-10-05', '2026-10-05')['rows'])->keyBy('user.id');

    expect($rows[$c->id])->toMatchArray(['received' => 0, 'first_reply_median_seconds' => null, 'points' => 3, 'outcomes' => []]);
});

it('writes the CSV: a translated header, the outcome columns by key, a totals line and no Excel formulas', function () {
    [$a] = trSetup();
    $a->update(['name' => '=HACK()']);
    $report = app(TeamReport::class);
    $rows = $report->csvRows($report->build('2026-10-05', '2026-10-05'));

    expect($rows)->toHaveCount(4) // header, two moderators, totals
        ->and($rows[0])->toHaveCount(15)
        ->and($rows[0][0])->toBe(__('queue.team_report.moderator'))
        ->and(array_slice($rows[0], 12))->toBe([__('labels.outcomes.ordered'), __('labels.outcomes.price'), 'zzz_new_key'])
        ->and($rows[1][0])->toBe("'=HACK()")
        ->and(array_slice($rows[1], 1))->toBe([5, 4, 90, 0, 1, 0, 1, 4.5, 2, 14, 2, 1, 1, 0])
        ->and($rows[3][0])->toBe(__('queue.team_report.totals'))
        ->and(array_slice($rows[3], 1))->toBe([8, 6, 90, 1, 2, 1, 1, 4.5, 2, 13, 2, 2, 1, 1]);
});

it('renders team_queue on /reports/team and streams the CSV to supervisors only', function () {
    [$a] = trSetup();
    $sup = User::factory()->create(['role' => 'supervisor']);

    // The page's props, not its assets: a checkout without a Vite build must still run this test.
    $props = $this->withoutVite()->actingAs($sup)->get('/reports/team?from=2026-10-05&to=2026-10-05')->assertOk()->viewData('page')['props'];
    expect($props['team_queue']['rows'])->toHaveCount(2)
        ->and($props['team_queue']['outcome_keys'])->toBe(['ordered', 'price', 'zzz_new_key'])
        ->and($props['team_queue']['totals']['handled'])->toBe(6);

    $res = $this->actingAs($sup)->get('/reports/team/queue-export?from=2026-10-05&to=2026-10-05')->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $csv = $res->streamedContent();
    expect($csv)->toStartWith("\u{FEFF}")->toContain('زينب')->toContain('zzz_new_key')->and(substr_count(trim($csv), "\n"))->toBe(3);

    $this->actingAs($a)->get('/reports/team/queue-export?from=2026-10-05&to=2026-10-05')->assertForbidden();
    $this->actingAs($sup)->get('/reports/team/queue-export?from=2026-01-01&to=2026-10-05')->assertSessionHasErrors('to');
});

it('gives the page an empty report, flagged, when the range is longer than the report allows', function () {
    $r = app(TeamReport::class)->build('2026-01-01', '2026-10-05');

    expect($r['too_long'])->toBeTrue()->and($r['rows'])->toBe([])->and($r['outcome_keys'])->toBe([]);
});
