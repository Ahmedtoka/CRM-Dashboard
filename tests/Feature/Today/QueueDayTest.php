<?php

use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\User;
use App\Today\QueueDay;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-06 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

it('matches the board numbers for today', function () {
    $sup = User::factory()->create(['role' => 'supervisor']);
    Shift::factory()->create(['leader_user_id' => $sup->id]);
    QueueEntry::factory()->create(['status' => 'closed', 'close_reason' => 'inquiry', 'first_reply_at' => now(), 'sla_met' => true, 'enqueued_at' => now()->subMinutes(10), 'called_at' => now()->subMinutes(6)]);
    QueueEntry::factory()->create(['status' => 'closed', 'close_reason' => 'problem', 'first_reply_at' => now(), 'sla_met' => false, 'enqueued_at' => now()->subMinutes(20), 'called_at' => now()->subMinutes(10)]);
    QueueEntry::factory()->create(['status' => 'abandoned', 'close_reason' => null]);
    QueueEntry::factory()->create(['status' => 'waiting']);

    $day = app(QueueDay::class)->for('2026-10-06');
    $board = $this->actingAs($sup)->getJson('/board/state')->json('data.kpis');

    expect($day)->toMatchArray([
        'issued' => $board['issued'], 'closed_manual' => $board['closed_manual'], 'closed_total' => $board['closed_total'],
        'sla_replied' => $board['sla_replied'], 'sla_pct' => $board['sla_pct'], 'sla_target_pct' => $board['sla_target_pct'],
        'abandoned' => 1, 'avg_wait_seconds' => 420, // (4 + 10) / 2 minutes
    ])->and($day['closed'])->toBe($board['closed']);
});

it('reads a past day on its own', function () {
    QueueEntry::factory()->create(['business_date' => '2026-10-05', 'status' => 'closed', 'close_reason' => 'inquiry', 'first_reply_at' => now(), 'sla_met' => true]);
    QueueEntry::factory()->create(['status' => 'closed', 'close_reason' => 'inquiry']); // today

    $y = app(QueueDay::class)->for('2026-10-05');

    expect($y['closed_manual'])->toBe(1)->and($y['sla_pct'])->toBe(100)->and($y['avg_wait_seconds'])->toBeNull();
});
