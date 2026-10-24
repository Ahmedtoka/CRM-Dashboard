<?php

use App\Models\Conversation;
use App\Models\ConversationOutcome;
use App\Models\QueueEntry;
use App\Models\SupportCase;
use App\Models\User;
use App\Queue\Reports\OutcomesReport;
use Illuminate\Support\Carbon;

// Reports › الفريق › «نتائج المحادثات» (2026-10-24, finish-api.md §5).

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-24 12:00', 'Africa/Cairo')));

/** An ended episode of `$user` on `$day` (Cairo), closed `$seconds` after it started. */
function orEpisode(?User $user, string $outcome, string $day = '2026-10-24', array $attrs = [], int $seconds = 300): ConversationOutcome
{
    $at = Carbon::parse($day.' 10:00', 'Africa/Cairo');
    $c = Conversation::factory()->create(['is_test' => $attrs['is_test'] ?? false]);

    return ConversationOutcome::create([
        'conversation_id' => $c->id, 'episode_key' => 'k'.uniqid(), 'outcome' => $outcome, 'source' => $user ? 'agent' : 'auto',
        'set_by_id' => $user?->id, 'set_at' => $at->copy()->addSeconds($seconds), 'started_at' => $at, 'ended_at' => $at->copy()->addSeconds($seconds),
        'ended_by' => $user ? 'close' : 'idle', 'reached_agent' => $user !== null,
    ] + collect($attrs)->except('is_test')->all());
}

function orSetup(): array
{
    $a = User::factory()->create(['name' => 'زينب', 'role' => 'moderator']);
    $b = User::factory()->create(['name' => 'منار', 'role' => 'moderator']);

    orEpisode($a, 'ordered', attrs: ['suggested_outcome' => 'ordered', 'suggestion_followed' => true], seconds: 120);
    orEpisode($a, 'no_answer', attrs: ['suggested_outcome' => 'no_answer', 'suggestion_followed' => true], seconds: 600);
    orEpisode($a, 'return_case', attrs: ['suggested_outcome' => 'no_answer', 'suggestion_followed' => false], seconds: 180);
    $pending = orEpisode($a, 'complaint_case', seconds: 300);
    $pending->forceFill(['support_case_id' => SupportCase::factory()->create(['conversation_id' => $pending->conversation_id, 'type' => 'complaint', 'status' => 'new'])->id])->save();
    $solved = orEpisode($b, 'complaint_case', '2026-10-23');
    $solved->forceFill(['support_case_id' => SupportCase::factory()->create(['conversation_id' => $solved->conversation_id, 'type' => 'complaint', 'status' => 'resolved'])->id])->save();
    orEpisode($b, 'spam', '2026-10-23');
    orEpisode($b, 'price', '2026-10-23');
    orEpisode(null, 'no_answer', '2026-10-23'); // the idle sweep: automatic
    orEpisode(null, 'unknown', '2026-10-23');
    orEpisode($a, 'ordered', attrs: ['is_test' => true]); // never counted
    orEpisode($a, 'ordered', '2026-10-01'); // another range
    orEpisode($a, 'service', '2026-10-23'); // a legacy row: read as an order inquiry

    return [$a, $b];
}

it('counts what happened per outcome, per moderator and per day, leaving spam and unknown out of the shares', function () {
    [$a, $b] = orSetup();

    $r = app(OutcomesReport::class)->build('2026-10-23', '2026-10-24');

    expect($r['kpis']['total'])->toBe(10)->and($r['kpis']['counted'])->toBe(8)
        ->and($r['by_outcome']['ordered'])->toBe(1)->and($r['by_outcome']['no_answer'])->toBe(2)->and($r['by_outcome']['complaint_case'])->toBe(2)
        ->and($r['by_outcome']['order_inquiry'])->toBe(1)->and($r['by_outcome']['spam'])->toBe(1)->and($r['by_outcome']['unknown'])->toBe(1)
        ->and($r['kpis']['no_reply_pct'])->toBe(25)->and($r['kpis']['purchases'])->toBe(1)->and($r['kpis']['returns'])->toBe(1)
        ->and($r['kpis']['complaints'])->toBe(2)->and($r['kpis']['complaints_pending'])->toBe(1)
        ->and($r['kpis']['suggested'])->toBe(3)->and($r['kpis']['followed'])->toBe(2)->and($r['kpis']['followed_pct'])->toBe(67)
        ->and($r['kpis']['automatic'])->toBe(2);

    $rows = collect($r['rows']);
    expect($rows->pluck('user.name')->all())->toBe(['زينب', 'منار', null]) // most chats first, the automatic row last
        ->and($rows[0]['total'])->toBe(5)->and($rows[0]['avg_close_seconds'])->toBe(300)->and($rows[0]['no_reply_pct'])->toBe(20)
        ->and($rows[0]['by_outcome']['return_case'])->toBe(1)->and($rows[0]['complaints_pending'])->toBe(1)->and($rows[0]['followed_pct'])->toBe(67)
        ->and($rows[1]['total'])->toBe(3)->and($rows[1]['by_outcome']['spam'])->toBe(1)->and($rows[1]['counted'])->toBe(2)
        ->and($rows[2]['user'])->toBeNull()->and($rows[2]['total'])->toBe(2);

    expect(collect($r['periods'])->pluck('key')->all())->toBe(['2026-10-23', '2026-10-24'])
        ->and($r['periods'][1]['total'])->toBe(4)->and($r['periods'][1]['purchases'])->toBe(1);
});

it('groups by week (Saturday start) and by month, and filters one moderator', function () {
    [$a, $b] = orSetup();

    $week = app(OutcomesReport::class)->build('2026-10-01', '2026-10-24', 'week');
    expect(collect($week['periods'])->pluck('key')->all())->toBe(['2026-09-26', '2026-10-17', '2026-10-24']); // Saturday starts a week

    $month = app(OutcomesReport::class)->build('2026-10-01', '2026-10-24', 'month');
    expect(collect($month['periods'])->pluck('key')->all())->toBe(['2026-10'])->and($month['periods'][0]['total'])->toBe(11);

    $mine = app(OutcomesReport::class)->build('2026-10-23', '2026-10-24', 'day', $b->id);
    expect($mine['kpis']['total'])->toBe(3)->and(collect($mine['rows'])->pluck('user.id')->all())->toBe([$b->id]);
});

it('writes the CSV with the outcome columns, a total row and the periods', function () {
    orSetup();
    $report = app(OutcomesReport::class);
    $rows = $report->csvRows($report->build('2026-10-23', '2026-10-24'));

    expect($rows[0])->toContain('الموظفة', 'اشترت / اتعمل أوردر', 'ما ردّتش', 'شكاوى لسه مفتوحة')
        ->and($rows[1][0])->toBe('زينب')->and($rows[1][1])->toBe(5)
        ->and($rows[3][0])->toBe('تلقائي / البوت')
        ->and($rows[4][0])->toBe('الكل')->and($rows[4][1])->toBe(10)
        ->and($rows[5][0])->toBe('فترة 2026-10-23')->and(count($rows))->toBe(7);
});

it('serves /reports/outcomes and its CSV to supervisors only, for at most 92 days, with the moderators list', function () {
    [$a] = orSetup();
    $sup = User::factory()->create(['role' => 'supervisor']);

    $props = $this->actingAs($sup)->get('/reports/outcomes?from=2026-10-23&to=2026-10-24&group=week')->assertOk()->viewData('page')['props'];
    expect($props['report']['kpis']['total'])->toBe(10)->and($props['group'])->toBe('week')->and(collect($props['moderators'])->pluck('name'))->toContain('زينب');

    $csv = $this->actingAs($sup)->get('/reports/outcomes/export?from=2026-10-23&to=2026-10-24')->assertOk()->streamedContent();
    expect($csv)->toStartWith("\u{FEFF}")->toContain('زينب');

    $this->actingAs($a)->get('/reports/outcomes')->assertForbidden();
    $this->actingAs($sup)->get('/reports/outcomes?from=2026-01-01&to=2026-10-24')->assertSessionHasErrors('to');
    $this->actingAs($sup)->get('/reports/outcomes?group=year')->assertSessionHasErrors('group');
});

it('counts a queue window by its assignee when nobody picked, and uses the window time to close', function () {
    $u = User::factory()->create(['name' => 'هدى']);
    $e = QueueEntry::factory()->create(['assigned_user_id' => $u->id, 'status' => 'closed', 'handle_seconds' => 240]);
    orEpisode(null, 'order_inquiry', attrs: ['queue_entry_id' => $e->id, 'conversation_id' => $e->conversation_id]);

    $r = app(OutcomesReport::class)->build('2026-10-24', '2026-10-24');

    expect($r['rows'][0]['user']['id'])->toBe($u->id)->and($r['rows'][0]['avg_close_seconds'])->toBe(240)->and($r['by_outcome']['order_inquiry'])->toBe(1);
});
