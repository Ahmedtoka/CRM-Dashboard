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
 * Shifts and the people in them: «ابدأ اليوم» (today's shifts from the templates + roster),
 * the scheduled open/close transition, breaks, and offline detection from heartbeats.
 */
class ShiftService
{
    /** No heartbeat for this long: the member shows offline and gets no new chats. */
    public const OFFLINE_AFTER_SECONDS = 180;

    /** No heartbeat for this long: her open windows are handed to someone else. */
    public const REASSIGN_AFTER_SECONDS = 300;

    /** Breaks are staggered this many minutes apart, in groups of five. */
    private const BREAK_STAGGER_MINUTES = 20;

    /** Supervisors hear about a mass offline at most this often while it lasts. */
    public const MASS_OFFLINE_ALERT_MINUTES = 15;

    /** The desks that count as serving for the mass-offline safeguard. */
    private const SERVING = ['available', 'busy', 'pending_break'];

    /**
     * She has not logged in since she was put on this shift: no heartbeat ever, or none since
     * ONLINE_MINUTES before she joined (a moderator added while she is online has arrived).
     * Such a desk never "went dark": the tick marks it offline at once and the mass-offline
     * safeguard does not count it (flow revision §2).
     */
    public static function notArrived(ShiftMember $m): bool
    {
        $seen = $m->user?->last_seen_at;

        if ($seen === null) {
            return true;
        }

        return $m->joined_at !== null && $seen->lt($m->joined_at->copy()->subMinutes(PresenceTracker::ONLINE_MINUTES));
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

    /**
     * «ابدأ اليوم»: members for each of today's shifts, the roster remembered as the default,
     * and the shift that covers now opened (else the next one still ahead, else the first).
     *
     * `$leaders` names today's leader per shift key (null = nobody); a shift key that is not in
     * it keeps the leader of its template. A leader always gets a desk on her shift.
     *
     * @param  array<string, list<int>>  $roster  user ids per shift key
     * @param  array<string, int|null>  $leaders  leader user id per shift key
     */
    public function startDay(array $roster, User $by, array $leaders = []): Shift
    {
        $roster = array_map(fn ($ids) => array_values(array_unique(array_map('intval', (array) $ids))), $roster);

        // All or nothing: a bad user id leaves no half-added roster, and the router runs once after commit.
        return DB::transaction(fn () => $this->startDayInTransaction($roster, $by, $leaders));
    }

    /**
     * @param  array<string, list<int>>  $roster
     * @param  array<string, int|null>  $leaders
     */
    private function startDayInTransaction(array $roster, User $by, array $leaders): Shift
    {
        $shifts = $this->todayShifts();

        foreach ($shifts as $shift) {
            if ($shift->status === 'closed') {
                continue;
            }

            if (array_key_exists($shift->shift_key, $leaders)) {
                $leaderId = $leaders[$shift->shift_key] === null ? null : (int) $leaders[$shift->shift_key];
                $shift->update(['leader_user_id' => $leaderId]);

                if ($leaderId !== null && ! in_array($leaderId, $roster[$shift->shift_key] ?? [], true)) {
                    $roster[$shift->shift_key][] = $leaderId;
                }
            }

            $serving = $shift->members()->where('status', '!=', 'left')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            foreach ($roster[$shift->shift_key] ?? [] as $userId) {
                // Already at her desk (the day was started before): she keeps her status and her break.
                if (in_array($userId, $serving, true)) {
                    continue;
                }

                $this->addMember($shift->fresh(), User::query()->findOrFail($userId), null, $by);
            }
        }

        QueueSetting::current()->update(['default_roster' => $roster]);

        $current = $shifts->first(fn (Shift $sh) => $sh->status !== 'closed' && $sh->starts_at->lte(now()) && $sh->ends_at->gt(now()))
            ?? $shifts->first(fn (Shift $sh) => $sh->status !== 'closed' && $sh->ends_at->gt(now()))
            ?? $shifts->first();
        $current = $current->fresh();

        if ($current->status !== 'open') {
            $this->open($current, $by);
        }

        return $current->fresh(['members.user']);
    }

    public function addMember(Shift $shift, User $user, ?int $cap, ?User $by = null): ShiftMember
    {
        $m = ShiftMember::query()->updateOrCreate(
            ['shift_id' => $shift->id, 'user_id' => $user->id],
            // She may come back with a window still open from before she left.
            ['status' => app(QueueRouter::class)->openForUser($user->id)->exists() ? 'busy' : 'available', 'windows_cap' => $cap, 'joined_at' => now(), 'left_at' => null],
        );

        if ($shift->status === 'open') {
            $index = $shift->members()->where('status', '!=', 'left')->where('id', '<', $m->id)->count();
            $this->scheduleBreak($m, $shift, $index);
            // After the commit: a start of day that rolls back shows no phantom desk on the board.
            DB::afterCommit(fn () => SafeBroadcast::send(new QueueMemberUpdated($m->fresh())));
            app(QueueRouter::class)->runAfterCommit('انضمام '.$user->name);
        }

        return $m;
    }

    public function removeMember(ShiftMember $m, ?User $by = null): void
    {
        $m->update(['status' => 'left', 'left_at' => now()]);
        $this->releaseReserved($m);
        SafeBroadcast::send(new QueueMemberUpdated($m->fresh()));
        app(QueueRouter::class)->runAfterCommit('خروج '.$m->user->name);
    }

    private function open(Shift $shift, ?User $by): void
    {
        $s = QueueSetting::current();
        $shift->update([
            'status' => 'open', 'opened_at' => now(), 'opened_by_id' => $by?->id,
            'settings_snapshot' => $s->only(['windows_per_moderator', 'silence_warn_seconds', 'silence_close_seconds', 'break_minutes', 'break_after_minutes']),
        ]);

        foreach ($shift->members()->where('status', '!=', 'left')->orderBy('id')->get()->values() as $i => $m) {
            $this->scheduleBreak($m, $shift, $i);
        }

        $this->splitOvernight($shift);

        // Escalations still waiting from the previous shift now belong to this shift's leader.
        QueueEntry::query()->where('status', 'waiting')->where('priority', 'escalation')
            ->update(['shift_id' => $shift->id, 'reserved_user_id' => $shift->leader_user_id]);

        $this->logger->log($by ? ActorType::User : ActorType::System, $by, ActivityLogger::SHIFT_OPEN, $shift, null, ['shift' => $shift->shift_key]);
        DB::afterCommit(fn () => SafeBroadcast::send(new ShiftUpdated($shift->fresh(['members.user', 'leader']))));
        // Each moderator's own channel too: an inbox opened before the day started shows her desk at once.
        $this->announceDesks($shift);
        app(QueueRouter::class)->runAfterCommit('بداية شيفت '.$shift->name);
    }

    /** `QueueMemberUpdated` (board + her own channel) for every desk of the shift, once committed. */
    private function announceDesks(Shift $shift): void
    {
        DB::afterCommit(function () use ($shift) {
            $shift->members()->with('user')->get()->each(fn (ShiftMember $m) => SafeBroadcast::send(new QueueMemberUpdated($m)));
        });
    }

    /**
     * Break time: `break_after_minutes` after she actually started (the shift start, or now when
     * the day was started late / she joined mid-shift), staggered by position (0, 20, 40, 60, 80 minutes, then again).
     */
    private function scheduleBreak(ShiftMember $m, Shift $shift, int $index): void
    {
        if ($m->break_at !== null) {
            return;
        }

        $s = QueueSetting::current();
        $anchor = $shift->starts_at->copy()->max(now());
        $m->update(['break_at' => $anchor->addMinutes($s->break_after_minutes + ($index % 5) * self::BREAK_STAGGER_MINUTES)]);
    }

    /** Overnight waiting entries are shared evenly (in ticket order, platform-aware) as personal queues. */
    private function splitOvernight(Shift $shift): void
    {
        // Not the leader: her desk serves escalations only (the router would hand her share to somebody else anyway).
        $members = $shift->members()->with('user.userPlatforms')->whereIn('status', ['available', 'busy'])
            ->when($shift->leader_user_id !== null, fn ($q) => $q->where('user_id', '!=', $shift->leader_user_id))
            ->orderBy('id')->get();

        if ($members->isEmpty()) {
            return;
        }

        $counts = $members->mapWithKeys(fn (ShiftMember $m) => [$m->user_id => 0])->all();
        $entries = QueueEntry::query()->with('conversation')->where('status', 'waiting')->where('priority', 'overnight')
            ->whereNull('reserved_user_id')->orderBy('business_date')->orderBy('ticket_no')->get();

        foreach ($entries as $e) {
            $platform = $e->conversation?->platform;
            $cand = $members->filter(fn (ShiftMember $m) => $platform !== null && $m->user->canAccessPlatform($platform));

            if ($cand->isEmpty()) {
                continue; // nobody on this shift may serve that platform: it stays in the shared pool
            }

            $pick = $cand->sort(fn (ShiftMember $x, ShiftMember $y) => [$counts[$x->user_id], $x->id] <=> [$counts[$y->user_id], $y->id])->first();
            $e->update(['reserved_user_id' => $pick->user_id, 'shift_id' => $shift->id]);
            $counts[$pick->user_id]++;
        }
    }

    /**
     * Scheduled (every minute): closes open shifts whose end passed — including yesterday's
     * evening shift that ran past midnight — and opens today's planned shift whose time came,
     * with the default roster when the leader did not set one.
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
                if ($shift->members()->count() === 0) {
                    foreach ($s->default_roster[$shift->shift_key] ?? [] as $userId) {
                        if ($u = User::query()->find($userId)) {
                            $this->addMember($shift, $u, null);
                        }
                    }
                }
                $this->open($shift, null);
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
     *
     * Last, the leader hears about members still not arrived `not_arrived_alert_minutes` into
     * their shift (once per member per shift).
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

        $this->alertNotArrived($members, $s);
    }

    /** Log every time, tell the supervisors at most every 15 minutes. */
    private function alertMassOffline(int $dark, int $serving): void
    {
        Log::warning('queue.mass_offline', ['dark' => $dark, 'serving' => $serving]);

        if (Cache::add('queue:mass-offline-notified', true, now()->addMinutes(self::MASS_OFFLINE_ALERT_MINUTES))) {
            $this->notifier->notifySupervisors('queue.mass_offline', ['count' => $dark, 'serving' => $serving]);
        }
    }

    /**
     * Once per member per shift: a rostered moderator still not logged in
     * `not_arrived_alert_minutes` after the shift opened, or after she was added when that is
     * later. The shift leader, the supervisors and the admins are told, each once, never the
     * member herself. The claim on her row makes two overlapping ticks tell them once.
     *
     * @param  Collection<int, ShiftMember>  $members
     */
    private function alertNotArrived(Collection $members, QueueSetting $s): void
    {
        foreach ($members as $m) {
            if ($m->not_arrived_alerted_at !== null || $m->shift === null || ! self::notArrived($m)) {
                continue;
            }

            $since = collect([$m->shift->opened_at ?? $m->shift->starts_at, $m->joined_at])->filter()->max();

            if ($since === null || $since->copy()->addMinutes((int) $s->not_arrived_alert_minutes)->gt(now())) {
                continue;
            }

            $claimed = ShiftMember::query()->whereKey($m->id)->whereNull('not_arrived_alerted_at')->toBase()
                ->update(['not_arrived_alerted_at' => now()]);

            if ($claimed !== 1) {
                continue;
            }

            $data = ['member_id' => $m->id, 'user_id' => (int) $m->user_id, 'name' => $m->user->name, 'shift' => $m->shift->name, 'minutes' => (int) $s->not_arrived_alert_minutes];

            User::query()->where('is_active', true)->get()
                ->filter(fn (User $u) => $u->isSupervisorOrAbove() || (int) $u->id === (int) $m->shift->leader_user_id)
                ->reject(fn (User $u) => (int) $u->id === (int) $m->user_id)
                ->each(fn (User $u) => $this->notifier->notify($u, 'queue.member_not_arrived', $data));
        }
    }
}
