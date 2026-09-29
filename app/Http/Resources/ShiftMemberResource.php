<?php

namespace App\Http\Resources;

use App\Analytics\PresenceTracker;
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

        $windows = $m->openEntries()->with('conversation')->orderBy('window_no')->orderBy('id')->get()
            ->map(fn (QueueEntry $e) => self::window($e))->values()->all();

        $byReason = QueueEntry::query()->where('shift_member_id', $m->id)->whereNotNull('close_reason')
            ->selectRaw('close_reason, COUNT(*) as n')->groupBy('close_reason')->pluck('n', 'close_reason')->all();
        $received = QueueEntry::query()->where('shift_member_id', $m->id)->count();

        return self::shape($m, $windows, self::today($received, $byReason), $m->cap());
    }

    /**
     * The desk from values the caller already holds (the board snapshot shapes every desk from
     * a handful of grouped queries).
     *
     * @param  list<array<string, mixed>>  $windows
     * @param  array<string, int>  $today
     * @return array<string, mixed>
     */
    public static function shape(ShiftMember $m, array $windows, array $today, int $cap): array
    {
        $user = $m->user;

        return [
            'id' => $m->id,
            'shift_id' => $m->shift_id,
            'user' => $user ? ['id' => $user->id, 'name' => $user->name, 'color' => $user->color] : null,
            'status' => $m->status,
            // Logged in right now (a heartbeat in the last 2 minutes, account active). A serving
            // desk whose moderator is not is «مش فاتحة» on the board: the router skips it.
            'online' => $user !== null && (bool) $user->is_active && app(PresenceTracker::class)->isOnline($user),
            'cap' => $cap,
            'open_count' => count($windows),
            'windows' => $windows,
            'break_at' => $m->break_at?->toIso8601String(),
            'break_ends_at' => $m->break_ends_at?->toIso8601String(),
            'joined_at' => $m->joined_at?->toIso8601String(),
            'today' => $today,
        ];
    }

    /** @return array<string, mixed> */
    public static function window(QueueEntry $e, ?QueueSetting $settings = null): array
    {
        return [
            'entry_id' => $e->id,
            'ticket' => $e->ticket_no,
            'window_no' => $e->window_no,
            'kind' => $e->kind,
            'platform' => $e->conversation?->platform?->value,
            'delivered_at' => $e->delivered_at?->toIso8601String(),
            // Null while the silence clock is not running (no reply yet, or she wrote last).
            'silence_left_seconds' => QueueEntryResource::silenceLeft($e, $settings),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $byReason  closed windows per close reason
     * @return array<string, int>
     */
    public static function today(int $received, array $byReason): array
    {
        $today = ['received' => $received];

        foreach (self::COUNTED_REASONS as $reason) {
            $today[$reason] = (int) ($byReason[$reason] ?? 0);
        }

        return $today;
    }
}
