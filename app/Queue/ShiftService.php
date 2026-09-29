<?php

namespace App\Queue;

use App\Analytics\ActivityLogger;
use App\Analytics\PresenceTracker;
use App\Enums\ActorType;
use App\Inbox\UserNotifier;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use App\Queue\Events\QueueMemberUpdated;
use App\Queue\Events\ShiftUpdated;
use App\Support\SafeBroadcast;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Shifts and the people in them: the shift templates open and close by the clock
 * (`transition()`, run by `queue:tick`); nobody starts the day and nobody picks a roster
 * (attendance design 2026-09-29). Breaks, and offline detection from heartbeats.
 */
class ShiftService
{
    /** No heartbeat for this long: the member shows offline and gets no new chats. */
    public const OFFLINE_AFTER_SECONDS = 180;

    /** No heartbeat for this long: her open windows are handed to someone else. */
    public const REASSIGN_AFTER_SECONDS = 300;

    /** Supervisors hear about a mass offline at most this often while it lasts. */
    public const MASS_OFFLINE_ALERT_MINUTES = 15;

    /** The desks that count as serving for the mass-offline safeguard. */
    private const SERVING = ['available', 'busy', 'pending_break'];

    /**
     * The moment she was expected at this shift's desk: when the shift opened (its start when it
     * has not opened yet), or when she was put on it if that is later. A roster adds her to every
     * shift of the day when the day starts, so `joined_at` alone can be hours before her shift.
     */
    public static function expectedFrom(ShiftMember $m): ?Carbon
    {
        $shift = $m->shift;
        $from = $shift !== null ? ($shift->opened_at ?? $shift->starts_at) : null;

        return collect([$from, $m->joined_at])->filter()->max();
    }

    /**
     * She has not logged in since she was expected at this shift (see `expectedFrom()`): no
     * heartbeat ever, or none since ONLINE_MINUTES before that (a moderator who is online when
     * her shift opens has arrived). Such a desk never "went dark": the tick marks it offline at
     * once and the mass-offline safeguard does not count it (flow revision §2).
     */
    public static function notArrived(ShiftMember $m): bool
    {
        $seen = $m->user?->last_seen_at;

        if ($seen === null) {
            return true;
        }

        $from = self::expectedFrom($m);

        return $from !== null && $seen->lt($from->copy()->subMinutes(PresenceTracker::ONLINE_MINUTES));
    }

    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly QueueService $queue,
        private readonly UserNotifier $notifier,
    ) {}

    /**
     * Today's shifts (the Cairo business date) from the templates, created as `planned` when missing.
     * A template whose `to` is not after `from` (18:00 → 00:00) ends the next day.
     *
     * @return Collection<int, Shift>
     */
    public function todayShifts(): Collection
    {
        $date = $this->queue->businessDate();
        $day = Carbon::parse($date, QueueService::TZ)->startOfDay();

        return collect(QueueSetting::current()->shiftTemplates())->map(function (array $t) use ($date, $day) {
            [$fh, $fm] = array_map('intval', explode(':', (string) $t['from']));
            [$th, $tm] = array_map('intval', explode(':', (string) $t['to']));
            $starts = $day->copy()->setTime($fh, $fm);
            $ends = $day->copy()->setTime($th, $tm);

            if ($ends->lte($starts)) {
                $ends->addDay();
            }

            // `date` is cast: compare with the exact string Eloquent writes (matches on SQLite, uses the unique index on MySQL).
            $stored = (new Shift)->fromDateTime(Carbon::parse($date));
            $find = fn () => Shift::query()->where('date', $stored)->where('shift_key', $t['key'])->first();

            try {
                return $find() ?? Shift::query()->create([
                    'date' => $date, 'shift_key' => $t['key'], 'name' => $t['name'], 'starts_at' => $starts->utc(), 'ends_at' => $ends->utc(),
                    'leader_user_id' => $t['leader_user_id'] ?? null, 'status' => 'planned',
                ]);
            } catch (UniqueConstraintViolationException) {
                return $find(); // a concurrent tick created it first
            }
        })->values();
    }

    public function removeMember(ShiftMember $m, ?User $by = null): void
    {
        $m->update(['status' => 'left', 'left_at' => now()]);
        $this->releaseReserved($m);
        SafeBroadcast::send(new QueueMemberUpdated($m->fresh()));
        app(QueueRouter::class)->runAfterCommit('خروج '.$m->user->name);
    }

    /**
     * Opens a planned shift by the clock, with nobody on it: moderators check themselves in
     * (attendance design §2). The conditional update claims the row, so the tick and a check-in
     * that race for the same shift open it once. Escalations still waiting move to its leader;
     * overnight customers stay in the shared pool and go, in ticket order, to whoever checks in.
     */
    private function open(Shift $shift): void
    {
        $s = QueueSetting::current();
        $claimed = Shift::query()->whereKey($shift->id)->where('status', 'planned')->update(['status' => 'open', 'opened_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $shift->refresh();
        $shift->update(['settings_snapshot' => $s->only(['windows_per_moderator', 'silence_warn_seconds', 'silence_close_seconds', 'break_minutes', 'break_after_minutes'])]);

        // Escalations still waiting from the previous shift now belong to this shift's leader.
        QueueEntry::query()->where('status', 'waiting')->where('priority', 'escalation')
            ->update(['shift_id' => $shift->id, 'reserved_user_id' => $shift->leader_user_id]);

        $this->logger->log(ActorType::System, null, ActivityLogger::SHIFT_OPEN, $shift, null, ['shift' => $shift->shift_key]);
        DB::afterCommit(fn () => SafeBroadcast::send(new ShiftUpdated($shift->fresh(['members.user', 'leader']))));
        app(QueueRouter::class)->runAfterCommit('بداية شيفت '.$shift->name);
    }

    /**
     * Scheduled (every tick): closes open shifts whose end passed — including yesterday's
     * evening shift that ran past midnight — and opens today's planned shift whose time came,
     * with nobody on it (attendance design §2: no roster, no «ابدأ اليوم»).
     */
    public function transition(?QueueSetting $settings = null): void
    {
        $s = $settings ?? QueueSetting::current();

        if (! $s->enabled) {
            return;
        }

        Shift::query()->where('status', 'open')->where('ends_at', '<=', now())->orderBy('ends_at')->get()
            ->each(fn (Shift $shift) => $this->close($shift));

        foreach ($this->todayShifts() as $shift) {
            if ($shift->status === 'planned' && $shift->starts_at->lte(now()) && $shift->ends_at->gt(now())) {
                $this->open($shift);
            }
        }
    }

    private function close(Shift $shift): void
    {
        $shift->update(['status' => 'closed', 'closed_at' => now()]);

        foreach ($shift->members()->with('user')->where('status', '!=', 'left')->get() as $m) {
            $m->update(['status' => 'left', 'left_at' => now()]);
            $this->releaseReserved($m);
            // Her own channel: the inbox strip of this shift goes away without a reload.
            SafeBroadcast::send(new QueueMemberUpdated($m));
        }

        SafeBroadcast::send(new ShiftUpdated($shift->fresh(['members.user', 'leader'])));
    }

    /** Her unfinished reserved (overnight) entries go back to the shared pool. */
    private function releaseReserved(ShiftMember $m): void
    {
        QueueEntry::query()->where('reserved_user_id', $m->user_id)->where('status', 'waiting')->where('priority', 'overnight')
            ->update(['reserved_user_id' => null]);
    }

    /**
     * `available | break | offline` (by the member, the leader, or the tick), decided under the
     * member's row lock (the router locks it last when it assigns, so the two never cross: a
     * break asked while a customer is being given to her sees that window). A break with open
     * windows (counted per USER, across her shift rows) waits as `pending_break`. A break is the
     * configured length and is not extended: asking again while on break (or waiting for it)
     * changes nothing. A member who left the shift is not changed.
     */
    public function setStatus(ShiftMember $m, string $status, ?User $by = null): void
    {
        if (! in_array($status, ['available', 'break', 'offline'], true)) {
            throw new \InvalidArgumentException('Unknown member status: '.$status);
        }

        $s = QueueSetting::current();

        $changed = DB::transaction(function () use ($m, $status, $s) {
            // The first statement is the locking read: the open-window count below then sees
            // whatever an assignment committed while we waited for the row.
            $locked = ShiftMember::query()->lockForUpdate()->find($m->id);

            if ($locked === null || $locked->status === 'left') {
                return false;
            }

            $open = app(QueueRouter::class)->openForUser((int) $locked->user_id)->exists();

            $attrs = match ($status) {
                'break' => match (true) {
                    $locked->status === 'break' => null,
                    $open => $locked->status === 'pending_break' ? null : ['status' => 'pending_break'],
                    default => ['status' => 'break', 'break_started_at' => now(), 'break_ends_at' => now()->addMinutes($s->break_minutes), 'break_at' => $locked->break_at ?? now()],
                },
                'available' => ['status' => $open ? 'busy' : 'available', 'break_ends_at' => null],
                'offline' => $locked->status === 'offline' ? null : ['status' => 'offline'],
            };

            if ($attrs === null) {
                return false;
            }

            $locked->update($attrs);

            return true;
        }, attempts: 3);

        if (! $changed) {
            return;
        }

        $m->refresh();
        SafeBroadcast::send(new QueueMemberUpdated($m));
        app(QueueRouter::class)->runAfterCommit('تغيير حالة '.$m->user->name);
    }

    /**
     * Scheduled (every tick): breaks start and end; heartbeats decide offline and the hand-off of
     * her windows (all her open windows, per user).
     *
     * Not arrived (flow revision §2): a member who never logged in since she joined is marked
     * offline at once (her windows, only ever given by hand, follow the usual 5-minute hand-off)
     * and is left out of the safeguard below.
     *
     * Mass-offline safeguard: when in one tick every serving desk that had arrived would turn
     * offline (two or more of them), or more than half of them with at least 3, the heartbeats
     * are what failed (Reverb, the network, the office), not the moderators. Then none of them is
     * marked offline and no window is handed off: a warning is logged, supervisors are told (at
     * most every 15 minutes) and the next tick looks again. One or two moderators going quiet on
     * their own is handled as before.
     */
    public function tickMembers(?QueueSetting $settings = null): void
    {
        $s = $settings ?? QueueSetting::current();

        if (! $s->enabled) {
            return;
        }

        $members = ShiftMember::query()->with(['user', 'shift'])->whereHas('shift', fn ($q) => $q->where('status', 'open'))
            ->where('status', '!=', 'left')->get()
            ->filter(fn (ShiftMember $m) => $m->user !== null);

        // Never seen (no heartbeat ever) or deactivated mid-shift counts as offline from the start.
        $offlineFor = fn (ShiftMember $m): int => ($m->user->last_seen_at && $m->user->is_active)
            ? (int) $m->user->last_seen_at->diffInSeconds(now()) : PHP_INT_MAX;

        // Only desks that were online at some point since joining can "go dark together".
        $serving = $members->filter(fn (ShiftMember $m) => in_array($m->status, self::SERVING, true) && ! self::notArrived($m));
        $goingDark = $serving->filter(fn (ShiftMember $m) => $offlineFor($m) >= self::OFFLINE_AFTER_SECONDS);
        // A lone serving desk going quiet is the isolated case (nothing to compare her with).
        $mass = $serving->count() >= 2 && $goingDark->isNotEmpty()
            && ($goingDark->count() === $serving->count() || ($serving->count() >= 3 && $goingDark->count() * 2 > $serving->count()));

        if ($mass) {
            $this->alertMassOffline($goingDark->count(), $serving->count());
        }

        $router = app(QueueRouter::class);

        foreach ($members as $m) {
            $away = $offlineFor($m);

            if ($m->status === 'break') {
                if ($m->break_ends_at && $m->break_ends_at->lte(now())) {
                    $this->setStatus($m, 'available');
                }

                continue;
            }

            $absent = self::notArrived($m);

            if ($absent || $away >= self::OFFLINE_AFTER_SECONDS) {
                if ($mass && ! $absent) {
                    continue; // the safeguard: not offline, no hand-off, try again next tick
                }

                if ($away >= self::REASSIGN_AFTER_SECONDS) {
                    foreach ($router->openForUser((int) $m->user_id)->get() as $e) {
                        app(WindowLifecycle::class)->transferAway($e, 'offline');
                    }
                    $m->refresh();
                }

                if ($m->status !== 'offline') {
                    $this->setStatus($m, 'offline');
                }

                continue;
            }

            if ($m->status === 'offline') {
                $this->setStatus($m, 'available');
                $m->refresh();
            }

            if ($m->break_at && $m->break_at->lte(now()) && $m->break_started_at === null && in_array($m->status, ['available', 'busy'], true)) {
                $this->setStatus($m, 'break');

                continue;
            }

            if ($m->status === 'pending_break' && ! $router->openForUser((int) $m->user_id)->exists()) {
                $this->setStatus($m, 'break');
            }
        }
    }

    /** Log every time, tell the supervisors at most every 15 minutes. */
    private function alertMassOffline(int $dark, int $serving): void
    {
        Log::warning('queue.mass_offline', ['dark' => $dark, 'serving' => $serving]);

        if (Cache::add('queue:mass-offline-notified', true, now()->addMinutes(self::MASS_OFFLINE_ALERT_MINUTES))) {
            $this->notifier->notifySupervisors('queue.mass_offline', ['count' => $dark, 'serving' => $serving]);
        }
    }
}
