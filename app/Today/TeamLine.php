<?php

namespace App\Today;

use App\Analytics\PresenceTracker;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\QueueEntry;
use App\Models\ShiftMember;
use App\Models\User;
use App\Queue\QueueService;
use App\Queue\RatingStats;
use Illuminate\Support\Carbon;

/** «الفريق» on /today: who is working, windows closed, orders made, rating; one grouped query per figure. */
final class TeamLine
{
    public function __construct(
        private readonly PresenceTracker $presence,
        private readonly QueueService $queue,
        private readonly RatingStats $ratings,
    ) {}

    /** @return list<array<string, mixed>> */
    public function for(TodayWindow $w): array
    {
        $users = User::query()->where('is_active', true)->inboxStaff()->orderBy('name')->get(['id', 'name', 'color']);
        if ($users->isEmpty()) {
            return [];
        }
        $ids = $users->pluck('id')->all();

        $online = $w->isToday() ? array_flip($this->presence->onlineUserIds()) : [];
        $open = $w->isToday() ? $this->queue->openShift() : null;
        $desks = $open ? ShiftMember::query()->where('shift_id', $open->id)->where('status', '!=', 'left')->pluck('status', 'user_id') : collect();
        $stored = (new QueueEntry)->fromDateTime(Carbon::parse($w->date));
        $closed = QueueEntry::query()->toBase()->where('business_date', $stored)->where('status', 'closed')
            ->whereIn('close_reason', QueueEntry::HANDLED_REASONS)->whereIn('assigned_user_id', $ids)
            ->groupBy('assigned_user_id')->selectRaw('assigned_user_id, COUNT(*) as n')->pluck('n', 'assigned_user_id');
        $orders = Order::query()->toBase()->whereIn('created_by_id', $ids)->whereBetween('created_at', [$w->from, $w->to])
            ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Failed->value])
            ->groupBy('created_by_id')->selectRaw('created_by_id, COUNT(*) as n')->pluck('n', 'created_by_id');
        $ratings = $this->ratings->byAgent($w->from, $w->to);

        $rows = $users->map(fn (User $u) => [
            'user' => ['id' => $u->id, 'name' => $u->name, 'color' => $u->color],
            'online' => isset($online[$u->id]),
            'desk_status' => $desks[$u->id] ?? null,
            'windows_closed' => (int) ($closed[$u->id] ?? 0),
            'orders' => (int) ($orders[$u->id] ?? 0),
            'rating' => $ratings[$u->id] ?? RatingStats::EMPTY,
            'href' => "/reports/users/{$u->id}?from={$w->date}&to={$w->date}",
        ])->filter(fn (array $r) => $r['online'] || $r['desk_status'] !== null || $r['windows_closed'] > 0 || $r['orders'] > 0 || $r['rating']['count'] > 0)
            ->sort(fn (array $a, array $b) => [$b['online'], $b['windows_closed'], $b['orders'], $a['user']['name']] <=> [$a['online'], $a['windows_closed'], $a['orders'], $b['user']['name']])
            ->values()->all();

        return $rows;
    }
}
