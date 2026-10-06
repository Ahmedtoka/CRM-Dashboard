<?php

use App\Http\Support\DateRange;
use App\Models\QueueEntry;
use App\Models\User;
use App\Queue\RatingStats;
use Illuminate\Support\Carbon;

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-06 12:00', 'Africa/Cairo')));

function rsRated(User $u, int $stars, Carbon $at, array $attrs = []): QueueEntry
{
    $at = $at->copy()->utc(); // the database holds UTC (app.timezone); a Cairo-zoned Carbon would be written as is

    return QueueEntry::factory()->create($attrs + [
        'assigned_user_id' => $u->id, 'status' => 'closed', 'close_reason' => 'inquiry', 'closed_at' => $at,
        'review_requested_at' => $at, 'review_stars' => $stars, 'reviewed_at' => $at,
    ]);
}

it('averages what customers answered in the window, counts 1-2 as low, and skips test chats', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    rsRated($a, 5, now()->subHour());
    rsRated($a, 2, now()->subMinutes(5));
    rsRated($b, 1, now()->subMinutes(30));
    rsRated($b, 4, now()->subDay());                   // yesterday
    rsRated($a, 1, now()->subMinute(), ['is_test' => true]);
    QueueEntry::factory()->create(['assigned_user_id' => $a->id, 'status' => 'closed']); // never rated

    $s = app(RatingStats::class);
    $from = DateRange::startOfCairoDay('2026-10-06');

    expect($s->summary($from, now()))->toBe(['count' => 3, 'avg' => 2.7, 'low' => 2])
        ->and($s->summary($from, now(), $a->id))->toBe(['count' => 2, 'avg' => 3.5, 'low' => 1])
        ->and($s->byAgent($from, now()))->toEqual([$a->id => ['count' => 2, 'avg' => 3.5, 'low' => 1], $b->id => ['count' => 1, 'avg' => 1.0, 'low' => 1]])
        ->and($s->summary(DateRange::startOfCairoDay('2026-10-07'), DateRange::endOfCairoDay('2026-10-07')))->toBe(RatingStats::EMPTY);
});

it('splits per agent per Cairo day of the answer', function () {
    $a = User::factory()->create();
    rsRated($a, 5, Carbon::parse('2026-10-06 00:30', 'Africa/Cairo')); // 21:30 UTC on the 5th: still the 6th in Cairo
    rsRated($a, 3, Carbon::parse('2026-10-05 23:30', 'Africa/Cairo'));
    rsRated($a, 1, Carbon::parse('2026-10-05 10:00', 'Africa/Cairo'));

    $rows = app(RatingStats::class)->byAgentAndDay(DateRange::startOfCairoDay('2026-10-05'), now());

    expect($rows)->toBe([
        ['user_id' => $a->id, 'date' => '2026-10-06', 'count' => 1, 'avg' => 5.0, 'low' => 0],
        ['user_id' => $a->id, 'date' => '2026-10-05', 'count' => 2, 'avg' => 2.0, 'low' => 1],
    ]);
});

it('lists the newest answers, the low ones alone on request', function () {
    $a = User::factory()->create();
    $low = rsRated($a, 1, now()->subMinutes(10));
    $high = rsRated($a, 5, now()->subMinutes(5));

    $s = app(RatingStats::class);
    $from = DateRange::startOfCairoDay('2026-10-06');

    expect(collect($s->recent($from, now(), false))->pluck('entry_id')->all())->toBe([$high->id, $low->id])
        ->and($s->recent($from, now(), true))->toHaveCount(1)
        ->and($s->recent($from, now(), true)[0])->toMatchArray(['entry_id' => $low->id, 'stars' => 1, 'user_id' => $a->id, 'conversation_id' => $low->conversation_id]);
});
