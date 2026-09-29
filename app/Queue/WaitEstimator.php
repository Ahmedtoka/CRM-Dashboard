<?php

namespace App\Queue;

use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\ShiftMember;
use App\Queue\Jobs\SendQueueMessage;
use Carbon\Carbon;

/**
 * How long a waiting customer still has to wait, and the «باقي 5 / 3 / 1 دقايق» messages
 * (each once per entry) plus a spaced apology when the estimate runs out and she is still waiting.
 */
class WaitEstimator
{
    /** Minutes-left message => the estimate (seconds) at or below which it is sent. */
    public const THRESHOLDS = [5 => 300, 3 => 180, 1 => 60];

    /** Estimate when nobody who can take this platform is on shift. */
    public const NO_MEMBERS_SECONDS = 30 * 60;

    /** Average handle time of the last 20 manual closes (at least 5 of them), else the setting. */
    public function avgHandleSeconds(): int
    {
        $recent = QueueEntry::query()->whereNotNull('handle_seconds')->where('close_reason', '!=', 'auto')
            ->latest('closed_at')->limit(20)->pluck('handle_seconds');

        return $recent->count() >= 5 ? max(60, (int) round($recent->avg())) : (int) QueueSetting::current()->eta_default_handle_seconds;
    }

    /** 1-based place among the live waiting entries (overnight tickets are served when the day opens). */
    public function position(QueueEntry $e): int
    {
        return QueueEntry::query()
            ->where('status', 'waiting')
            ->where('priority', '!=', 'overnight')
            ->where('id', '!=', $e->id)
            ->where(fn ($q) => $q->where('enqueued_at', '<', $e->enqueued_at)
                ->orWhere(fn ($q) => $q->where('enqueued_at', $e->enqueued_at)->where('id', '<', $e->id)))
            ->count() + 1;
    }

    /**
     * Seconds until a window frees up for her: every window of every member who can take the
     * conversation's platform frees after the average handle time minus what it has already
     * spent (an empty window is free now); she gets the `position`-th one, round-robin.
     */
    public function eta(QueueEntry $e): int
    {
        $pos = $this->position($e) - 1;
        $platform = $e->conversation->platform;
        $members = ShiftMember::query()->with('user.userPlatforms')
            ->whereHas('shift', fn ($q) => $q->where('status', 'open'))
            ->whereIn('status', ['available', 'busy'])
            ->get()
            ->filter(fn (ShiftMember $m) => $m->user !== null && $m->user->canAccessPlatform($platform));

        if ($members->isEmpty()) {
            return self::NO_MEMBERS_SECONDS;
        }

        $avg = $this->avgHandleSeconds();
        $rem = [];

        foreach ($members as $m) {
            $open = $m->openEntries()->get()->values();

            for ($k = 0; $k < $m->cap(); $k++) {
                $entry = $open[$k] ?? null;
                $spent = $entry?->delivered_at !== null ? (int) abs($entry->delivered_at->diffInSeconds(now())) : 0;
                $rem[] = $entry ? max(30, $avg - $spent) : 0;
            }
        }

        if ($rem === []) {
            return self::NO_MEMBERS_SECONDS;
        }

        sort($rem);
        $n = count($rem);

        return $rem[$pos % $n] + intdiv($pos, $n) * $avg;
    }

    /** Thresholds already below the estimate at enqueue are marked sent so a short wait only gets the remaining ones. */
    public function alreadyPassed(int $eta): array
    {
        $out = [];

        foreach (self::THRESHOLDS as $m => $sec) {
            if ($eta <= $sec) {
                $out[(string) $m] = true;
            }
        }

        return $out;
    }

    public function tickWaiting(QueueEntry $e): void
    {
        if ($e->status !== 'waiting' || $e->priority === 'overnight') {
            return;
        }

        $left = $e->eta_seconds ?? $this->eta($e);
        $sent = $e->waiting_messages ?? [];

        foreach (self::THRESHOLDS as $m => $sec) {
            if ($left <= $sec && empty($sent[(string) $m])) {
                $sent[(string) $m] = true;
                SendQueueMessage::dispatch($e->id, 'queue_left_'.$m, []);
            }
        }

        // The last estimate ran out and she is still waiting: apologise, at most every 5 minutes.
        if (! empty($sent['1']) && $left <= 35 && abs($e->enqueued_at->diffInSeconds(now())) > 90) {
            $last = isset($sent['apology_at']) ? Carbon::parse($sent['apology_at']) : null;

            if ($last === null || abs($last->diffInSeconds(now())) >= 300) {
                $sent['apology_at'] = now()->toIso8601String();
                SendQueueMessage::dispatch($e->id, 'queue_apology', []);
            }
        }

        $e->forceFill(['waiting_messages' => $sent, 'eta_seconds' => $this->eta($e)])->save();
    }
}
