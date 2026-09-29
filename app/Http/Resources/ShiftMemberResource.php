<?php

namespace App\Http\Resources;

use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\ShiftMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One moderator's desk on the live board: status, open windows (with the silence countdown)
 * and today's counters. `today.received` counts every entry she was given this shift; the
 * others count her closed windows by close reason (Part 2 adds points).
 *
 * @mixin ShiftMember
 */
class ShiftMemberResource extends JsonResource
{
    public const COUNTED_REASONS = ['inquiry', 'problem', 'case', 'auto', 'escalation'];

    public function toArray(Request $request): array
    {
        /** @var ShiftMember $m */
        $m = $this->resource;
        $user = $m->user;
        $silenceClose = (int) QueueSetting::current()->silence_close_seconds;

        $windows = $m->openEntries()->with('conversation')->orderBy('window_no')->orderBy('id')->get()
            ->map(function (QueueEntry $e) use ($silenceClose) {
                $last = $e->conversation?->last_customer_message_at ?? $e->delivered_at ?? $e->enqueued_at;
                $idle = $last ? (int) $last->diffInSeconds(now()) : 0;

                return [
                    'entry_id' => $e->id,
                    'ticket' => $e->ticket_no,
                    'window_no' => $e->window_no,
                    'kind' => $e->kind,
                    'platform' => $e->conversation?->platform?->value,
                    'delivered_at' => $e->delivered_at?->toIso8601String(),
                    'silence_left_seconds' => max(0, $silenceClose - $idle),
                ];
            })->values()->all();

        $byReason = QueueEntry::query()->where('shift_member_id', $m->id)->whereNotNull('close_reason')
            ->selectRaw('close_reason, COUNT(*) as n')->groupBy('close_reason')->pluck('n', 'close_reason');
        $today = ['received' => QueueEntry::query()->where('shift_member_id', $m->id)->count()];
        foreach (self::COUNTED_REASONS as $reason) {
            $today[$reason] = (int) ($byReason[$reason] ?? 0);
        }

        return [
            'id' => $m->id,
            'shift_id' => $m->shift_id,
            'user' => $user ? ['id' => $user->id, 'name' => $user->name, 'color' => $user->color] : null,
            'status' => $m->status,
            'cap' => $m->cap(),
            'open_count' => count($windows),
            'windows' => $windows,
            'break_at' => $m->break_at?->toIso8601String(),
            'break_ends_at' => $m->break_ends_at?->toIso8601String(),
            'joined_at' => $m->joined_at?->toIso8601String(),
            'today' => $today,
        ];
    }
}
