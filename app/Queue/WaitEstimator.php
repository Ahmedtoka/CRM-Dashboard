<?php

namespace App\Queue;

use App\Analytics\PresenceTracker;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\ShiftMember;
use App\Queue\Data\DeskSnapshot;
use App\Queue\Jobs\SendQueueMessage;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How long a waiting customer still has to wait, and the «باقي 5 / 3 / 1 دقايق» messages
 * (each once per entry, and only the lowest one reached when the estimate falls through several
 * at once) plus a spaced apology when the estimate runs out and she is still waiting.
 *
 * Cost: the tick reads the desks once (`snapshot()`, a handful of queries whatever the size of
 * the lounge) and a customer whose estimate and messages did not change costs nothing more.
 */
class WaitEstimator
{
    /** Minutes-left message => the estimate (seconds) at or below which it is sent. */
    public const THRESHOLDS = [5 => 300, 3 => 180, 1 => 60];

    public function __construct(private readonly PresenceTracker $presence) {}

    /**
     * Average handle time of the last 20 windows a moderator really handled (inquiry / problem /
     * case / auto; at least 5 of them), else the setting. A transfer, a cancel or a resolve
     * elsewhere is not a handled window and would skew the estimate.
     */
    public function avgHandleSeconds(?QueueSetting $settings = null): int
    {
        $recent = QueueEntry::query()->whereNotNull('handle_seconds')->whereIn('close_reason', QueueEntry::HANDLED_REASONS)
            ->latest('closed_at')->limit(20)->pluck('handle_seconds');

        return $recent->count() >= 5 ? max(60, (int) round($recent->avg())) : (int) ($settings ?? QueueSetting::current())->eta_default_handle_seconds;
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
     * The serving desks of the open shift(s), how long each open window has run, the average
     * handle time and the leaders, read once. `$waiting` (the live lounge in the router's order:
     * enqueued_at, id) gives every customer her place without a query each.
     *
     * @param  Collection<int, QueueEntry>|null  $waiting
     */
    public function snapshot(?QueueSetting $settings = null, ?Collection $waiting = null): DeskSnapshot
    {
        $settings ??= QueueSetting::current();
        $members = ShiftMember::query()->with(['user.userPlatforms', 'shift'])
            ->whereHas('shift', fn ($q) => $q->where('status', 'open'))
            ->whereIn('status', ['available', 'busy'])
            ->get()
            // Only desks whose moderator is logged in serve (the router skips the others): a desk
            // on the roster that nobody opened must not promise the customer a minute.
            ->filter(fn (ShiftMember $m) => $m->user !== null && $m->user->is_active && $this->presence->isOnline($m->user))
            ->values();

        $spent = [];

        if ($members->isNotEmpty()) {
            QueueEntry::query()->whereIn('shift_member_id', $members->pluck('id'))->whereIn('status', QueueEntry::OPEN_STATUSES)
                ->orderBy('id')->get(['id', 'shift_member_id', 'delivered_at'])
                ->each(function (QueueEntry $e) use (&$spent) {
                    $spent[(int) $e->shift_member_id][] = $e->delivered_at !== null ? (int) abs($e->delivered_at->diffInSeconds(now())) : 0;
                });
        }

        $positions = [];

        foreach (($waiting ?? collect())->reject(fn (QueueEntry $e) => $e->priority === 'overnight')->values() as $i => $e) {
            $positions[$e->id] = $i + 1;
        }

        return new DeskSnapshot(
            settings: $settings,
            members: $members,
            spent: $spent,
            avg: $this->avgHandleSeconds($settings),
            leaderIds: $members->map(fn (ShiftMember $m) => $m->shift?->leader_user_id)->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
            positions: $positions,
        );
    }

    /**
     * Seconds until a window frees up for her: every window of every logged-in member who may
     * take her frees after the average handle time minus what it has already spent (an empty
     * window is free now); she gets the `position`-th one, round-robin. A live / returning /
     * overnight customer is never served by the leader of the shift (her desk takes escalations),
     * an escalation only by the leader or a supervisor.
     *
     * Null when nobody who may take her is logged in: there is no estimate, so the customer is
     * promised no minutes and gets no countdown (flow revision §2).
     */
    public function eta(QueueEntry $e, ?DeskSnapshot $snapshot = null): ?int
    {
        $snapshot ??= $this->snapshot();
        $pos = ($snapshot->positions[$e->id] ?? $this->position($e)) - 1;
        $platform = $e->conversation->platform;
        $escalation = $e->priority === 'escalation';

        $members = $snapshot->members->filter(function (ShiftMember $m) use ($platform, $escalation, $snapshot) {
            $leader = in_array((int) $m->user_id, $snapshot->leaderIds, true);

            return $m->user->canAccessPlatform($platform) && ($escalation ? ($leader || $m->user->isSupervisorOrAbove()) : ! $leader);
        });

        if ($members->isEmpty()) {
            return null;
        }

        $avg = $snapshot->avg;
        $rem = [];

        foreach ($members as $m) {
            $open = $snapshot->spent[(int) $m->id] ?? [];
            $cap = $m->cap($snapshot->settings);

            for ($k = 0; $k < $cap; $k++) {
                $rem[] = array_key_exists($k, $open) ? max(30, $avg - $open[$k]) : 0;
            }
        }

        if ($rem === []) {
            return null;
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

    /**
     * The tick's step for the whole lounge: the desks are read once, then every live waiting
     * customer is estimated from that snapshot. One customer whose estimate fails never stops
     * the messages of the others.
     */
    public function tickLounge(?QueueSetting $settings = null): void
    {
        $settings ??= QueueSetting::current();
        $waiting = QueueEntry::query()->with('conversation')->where('status', 'waiting')->where('priority', '!=', 'overnight')
            ->orderBy('enqueued_at')->orderBy('id')->get()
            ->filter(fn (QueueEntry $e) => $e->conversation !== null)
            ->values();

        if ($waiting->isEmpty()) {
            return;
        }

        $snapshot = $this->snapshot($settings, $waiting);
        $waiting->each(fn (QueueEntry $e) => rescue(fn () => $this->tickWaiting($e, $snapshot), null, report: true));
    }

    /**
     * One customer: the countdown flags are decided and written under the entry's row lock, so
     * two ticks that overlap never send the same message twice; the messages leave once that
     * transaction commits. When the estimate falls through several thresholds at once only the
     * lowest one is sent, the higher ones are marked as passed. Nothing to send and the same
     * estimate: no write at all; only a new estimate: one plain update, no lock.
     */
    public function tickWaiting(QueueEntry $e, ?DeskSnapshot $snapshot = null): void
    {
        if ($e->status !== 'waiting' || $e->priority === 'overnight') {
            return;
        }

        // One fresh estimate drives this tick's messages and is the one saved (no one-tick lag).
        $left = $this->eta($e, $snapshot);

        // No estimate (nobody who may take her is logged in): no countdown and no apology; only
        // the stored estimate is cleared so the board shows no minutes.
        if ($left === null) {
            if ($e->eta_seconds !== null) {
                QueueEntry::query()->whereKey($e->id)->where('status', 'waiting')->update(['eta_seconds' => null]);
                $e->eta_seconds = null;
            }

            return;
        }

        if (! $this->decide($e->waiting_messages ?? [], $left, $e)['changed']) {
            if ($e->eta_seconds === null || (int) $e->eta_seconds !== $left) {
                QueueEntry::query()->whereKey($e->id)->where('status', 'waiting')->update(['eta_seconds' => $left]);
                $e->eta_seconds = $left;
            }

            return;
        }

        DB::transaction(function () use ($e, $left) {
            $locked = QueueEntry::query()->lockForUpdate()->find($e->id);

            if ($locked === null || $locked->status !== 'waiting' || $locked->priority === 'overnight') {
                return;
            }

            ['sent' => $sent, 'send' => $send] = $this->decide($locked->waiting_messages ?? [], $left, $locked);

            foreach ($send as $key) {
                SendQueueMessage::dispatch($locked->id, $key, []);
            }

            $locked->forceFill(['waiting_messages' => $sent, 'eta_seconds' => $left])->save();
            $e->setRawAttributes($locked->getAttributes(), true);
        }, attempts: 3);
    }

    /**
     * What this estimate sends: the lowest threshold reached that was not sent yet (the higher
     * ones reached with it are marked as passed), then the apology when the last estimate ran out.
     *
     * @param  array<string, mixed>  $sent
     * @return array{sent: array<string, mixed>, send: list<string>, changed: bool}
     */
    private function decide(array $sent, int $left, QueueEntry $e): array
    {
        $send = [];
        $due = array_filter(self::THRESHOLDS, fn (int $sec, int $m) => $left <= $sec && empty($sent[(string) $m]), ARRAY_FILTER_USE_BOTH);

        if ($due !== []) {
            foreach (array_keys($due) as $m) {
                $sent[(string) $m] = true;
            }

            $send[] = 'queue_left_'.array_search(min($due), $due, true);
        }

        // The last estimate ran out and she is still waiting: apologise, at most every 5 minutes.
        if (! empty($sent['1']) && $left <= 35 && abs($e->enqueued_at->diffInSeconds(now())) > 90) {
            $last = isset($sent['apology_at']) ? Carbon::parse($sent['apology_at']) : null;

            if ($last === null || abs($last->diffInSeconds(now())) >= 300) {
                $sent['apology_at'] = now()->toIso8601String();
                $send[] = 'queue_apology';
            }
        }

        return ['sent' => $sent, 'send' => $send, 'changed' => $send !== []];
    }
}
