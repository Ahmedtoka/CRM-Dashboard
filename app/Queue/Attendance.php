<?php

namespace App\Queue;

use App\Models\QueueAttendanceEvent;
use App\Models\ShiftMember;
use App\Models\User;
use InvalidArgumentException;

/**
 * The attendance log of the queue (attendance design §4): one row per «بدأت شغل» (`in`),
 * «استراحة» (`break`, when the break really starts), «رجعت» (`back`), «خروج» (`out`, when she
 * really leaves) and the automatic check-out (`auto_out`: the shift closed, or 10 minutes
 * offline). Written by ShiftService inside the transaction that holds her shift-member row.
 * Nothing is paid or penalised from it; it is shown only.
 */
class Attendance
{
    public const EVENTS = ['in', 'break', 'back', 'out', 'auto_out'];

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
}
