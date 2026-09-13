<?php

namespace App\Analytics;

use App\Models\User;
use App\Models\UserSession;
use Carbon\CarbonInterface;

/**
 * Online time from client heartbeats. A heartbeat within 5 minutes of the previous one
 * extends the open session; otherwise the stale session is closed and a new one starts.
 */
class PresenceTracker
{
    public const MERGE_MINUTES = 5;

    public const ONLINE_MINUTES = 2;

    public function heartbeat(User $u, string $device = 'web'): void
    {
        $now = now();

        $open = UserSession::where('user_id', $u->id)
            ->whereNull('ended_at')
            ->orderByDesc('last_heartbeat_at')
            ->orderByDesc('id')
            ->first();

        if ($open !== null && $open->last_heartbeat_at !== null
            && $open->last_heartbeat_at->gt($now->copy()->subMinutes(self::MERGE_MINUTES))) {
            $open->update(['last_heartbeat_at' => $now]);
        } else {
            $this->closeOpenSessions($u, null);

            UserSession::create([
                'user_id' => $u->id,
                'started_at' => $now,
                'last_heartbeat_at' => $now,
                'device' => $device,
            ]);
        }

        $u->forceFill(['last_seen_at' => $now])->save();
    }

    public function end(User $u): void
    {
        $now = now();

        $this->closeOpenSessions($u, $now);

        $u->forceFill(['last_seen_at' => $now])->save();
    }

    /**
     * @return array<int, int>
     */
    public function onlineUserIds(): array
    {
        return UserSession::query()
            ->whereNull('ended_at')
            ->where('last_heartbeat_at', '>=', now()->subMinutes(self::ONLINE_MINUTES))
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Closes open sessions at $at, or (when null) at their last heartbeat so stale gaps are not counted.
     */
    private function closeOpenSessions(User $u, ?CarbonInterface $at): void
    {
        UserSession::where('user_id', $u->id)
            ->whereNull('ended_at')
            ->get()
            ->each(function (UserSession $s) use ($at) {
                $end = $at ?? $s->last_heartbeat_at ?? $s->started_at ?? now();
                $s->update(array_filter([
                    'ended_at' => $end,
                    'last_heartbeat_at' => $at,
                ], fn ($v) => $v !== null));
            });
    }
}
