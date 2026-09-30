<?php

namespace App\Queue;

use App\Models\QueueAttendanceEvent;
use App\Models\ShiftMember;
use App\Models\User;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * The attendance log of the queue (attendance design §4): one row per «بدأت شغل» (`in`),
 * «استراحة» (`break`, when the break really starts), «رجعت» (`back`), «خروج» (`out`, when she
 * really leaves) and the automatic check-out (`auto_out`: the shift closed, or 10 minutes
 * offline). Written by ShiftService inside the transaction that holds her shift-member row.
 * The day's figures are computed from it; nothing is paid or penalised from them.
 */
class Attendance
{
    public const EVENTS = ['in', 'break', 'back', 'out', 'auto_out'];

    /** The figures of somebody without a single event that day. */
    public const EMPTY = [
        'first_in' => null, 'last_out' => null, 'checked_in' => false,
        'worked_seconds' => 0, 'break_seconds' => 0, 'break_count' => 0, 'overruns' => 0,
    ];

    /** `$by` is who did it for her: null when she did it herself or the system did. */
    public function record(ShiftMember $m, string $event, ?User $by = null): QueueAttendanceEvent
    {
        if (! in_array($event, self::EVENTS, true)) {
            throw new InvalidArgumentException('Unknown attendance event: '.$event);
        }

        $m->loadMissing('shift');

        return QueueAttendanceEvent::query()->create([
            'user_id' => $m->user_id,
            'shift_id' => $m->shift_id,
            // Her shift's date: the evening shift's check-out after midnight still belongs to its day.
            'business_date' => $m->shift->date->toDateString(),
            'event' => $event,
            'at' => now(),
            'by_user_id' => $by?->id,
        ]);
    }

    /**
     * The day's figures per user (attendance §4), from one query whatever the number of users.
     * Users without an event that day get EMPTY.
     *
     * @param  list<int>  $userIds
     * @return array<int, array<string, mixed>> keyed by user id
     */
    public function figuresFor(array $userIds, string $businessDate, int $breakMinutes): array
    {
        if ($userIds === []) {
            return [];
        }

        $events = QueueAttendanceEvent::query()->whereIn('user_id', $userIds)->where('business_date', $businessDate)
            ->orderBy('at')->orderBy('id')->get()->groupBy('user_id');
        $figures = [];

        foreach ($userIds as $id) {
            $figures[$id] = $events->has($id) ? self::compute($events->get($id), $breakMinutes, now()) : self::EMPTY;
        }

        return $figures;
    }

    /**
     * Her day from her events, oldest first: the first `in`; the last `out` / `auto_out` (null
     * while she is in); time worked = the time checked in less the breaks; break time; the
     * number of breaks; how many ran past `break_minutes`. A break ends at `back`, or at the
     * next `out` / `auto_out` / `in` (a check-out from a break writes no `back`). A break or a
     * stay still open counts up to `$now`, and an open break is an overrun once it is past the limit.
     *
     * @param  iterable<QueueAttendanceEvent>  $events
     * @return array{first_in: ?string, last_out: ?string, checked_in: bool, worked_seconds: int, break_seconds: int, break_count: int, overruns: int}
     */
    public static function compute(iterable $events, int $breakMinutes, CarbonInterface $now): array
    {
        $limit = max(1, $breakMinutes) * 60;
        $in = null;
        $breakFrom = null;
        $firstIn = null;
        $lastOut = null;
        $present = 0;
        $breaks = 0;
        $count = 0;
        $over = 0;

        $closeBreak = function (CarbonInterface $at) use (&$breakFrom, &$breaks, &$over, $limit): void {
            $length = max(0, (int) $breakFrom->diffInSeconds($at, true));
            $breaks += $length;
            $over += (int) ($length > $limit);
            $breakFrom = null;
        };

        foreach ($events as $ev) {
            $at = $ev->at;

            if ($ev->event === 'in') {
                if ($breakFrom !== null) {
                    $closeBreak($at);
                }

                if ($in === null) {
                    $in = $at;
                    $firstIn ??= $at;
                }
            } elseif ($ev->event === 'break') {
                if ($in !== null && $breakFrom === null) {
                    $breakFrom = $at;
                    $count++;
                }
            } elseif ($ev->event === 'back') {
                if ($breakFrom !== null) {
                    $closeBreak($at);
                }
            } else { // out, auto_out
                if ($breakFrom !== null) {
                    $closeBreak($at);
                }

                if ($in !== null) {
                    $present += max(0, (int) $in->diffInSeconds($at, true));
                    $in = null;
                    $lastOut = $at;
                }
            }
        }

        if ($breakFrom !== null) {
            $closeBreak($now);
        }

        if ($in !== null) {
            $present += max(0, (int) $in->diffInSeconds($now, true));
        }

        return [
            'first_in' => $firstIn?->toIso8601String(),
            'last_out' => $in === null ? $lastOut?->toIso8601String() : null,
            'checked_in' => $in !== null,
            'worked_seconds' => max(0, $present - $breaks),
            'break_seconds' => $breaks,
            'break_count' => $count,
            'overruns' => $over,
        ];
    }
}
