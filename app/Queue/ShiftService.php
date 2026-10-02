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
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Shifts and the people in them (attendance design 2026-09-29). The shift templates open and
 * close by the clock (`transition()`, run by `queue:tick`); nobody starts the day and nobody
 * picks a roster. Each moderator checks herself in and out from her inbox strip:
 * «بدأت شغل» `checkIn()` · «استراحة» / «رجعت» `setStatus()` · «خروج» `checkOut()` (also when she
 * logs out, and by the leader on her behalf) · «رجّعي شبابيكي للصالة» `handBack()`.
 * A break or a check-out asked while she still holds windows waits (`pending_break`,
 * `checking_out`: no new chats) and `settle()` completes it when her last window closes. Every
 * step is written to `queue_attendance_events` (Attendance) when it really happens.
 *
 * The tick (`tickMembers()`): offline after 3 minutes without a heartbeat, her windows handed on
 * after 5, checked out after 10 (`auto_out`); a break never ends by itself, and the leader hears
 * once when it runs past `break_minutes`. The close of a shift checks out whoever is still in.
 *
 * Lock order: a shift-member row is always the LAST lock (the router takes the conversation,
 * then the entry, then the member). Nothing here touches a conversation or a queue entry while
 * it holds a member row: reserved overnight entries are released after the member transaction.
 */
class ShiftService
{
    /** No heartbeat for this long: the member shows offline and gets no new chats. */
    public const OFFLINE_AFTER_SECONDS = 180;

    /** No heartbeat for this long: her open windows are handed to someone else. */
    public const REASSIGN_AFTER_SECONDS = 300;

    /** No heartbeat for this long: she is checked out (`auto_out`, attendance §3). */
    public const AUTO_OUT_AFTER_SECONDS = 600;

    /** Supervisors hear about a mass offline at most this often while it lasts. */
    public const MASS_OFFLINE_ALERT_MINUTES = 15;

    /** The desks that count as serving for the mass-offline safeguard: checked in and not on a break. */
    private const SERVING = ['available', 'busy', 'pending_break', 'checking_out'];

    /** Waiting for her last window to close: a break to start, or her check-out to complete. */
    private const WAITING_FOR_WINDOWS = ['pending_break', 'checking_out'];

    /**
     * The moment she was expected at this shift's desk: when the shift opened (its start when it
     * has not opened yet), or when she was put on it if that is later.
     */
    public static function expectedFrom(ShiftMember $m): ?Carbon
    {
        $shift = $m->shift;
        $from = $shift !== null ? ($shift->opened_at ?? $shift->starts_at) : null;

        return collect([$from, $m->joined_at])->filter()->max();
    }

    /**
     * She has not logged in since she was expected at this shift (see `expectedFrom()`): no
     * heartbeat ever, or none since ONLINE_MINUTES before that. A check-in records a heartbeat,
     * so only a desk left from before the attendance design can be "not arrived"; the tick
     * checks it out and the mass-offline safeguard does not count it (flow revision §2).
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

    /** «بدأت شغل» is for an active account with at least one platform in Settings → Users (the role alone does not count). */
    public static function mayCheckIn(User $user): bool
    {
        return (bool) $user->is_active && $user->userPlatforms()->exists();
    }

    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly QueueService $queue,
        private readonly UserNotifier $notifier,
        private readonly PresenceTracker $presence,
        private readonly Attendance $attendance,
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
     * The template hours of one business date (Cairo), in template order, without touching the
     * `shifts` table. A template whose `to` is not after its `from` ends the next day.
     *
     * @return list<array{key: string, name: string, leader_user_id: ?int, starts: Carbon, ends: Carbon}>
     */
    public function templateHours(string $date, ?QueueSetting $settings = null): array
    {
        $day = Carbon::parse($date, QueueService::TZ)->startOfDay();

        return array_values(array_map(function (array $t) use ($day) {
            [$fh, $fm] = array_map('intval', explode(':', (string) $t['from']) + [0, 0]);
            [$th, $tm] = array_map('intval', explode(':', (string) $t['to']) + [0, 0]);
            $starts = $day->copy()->setTime($fh, $fm);
            $ends = $day->copy()->setTime($th, $tm);

            if ($ends->lte($starts)) {
                $ends->addDay();
            }

            return [
                'key' => (string) $t['key'], 'name' => (string) $t['name'],
                'leader_user_id' => isset($t['leader_user_id']) ? (int) $t['leader_user_id'] : null,
                'starts' => $starts, 'ends' => $ends,
            ];
        }, ($settings ?? QueueSetting::current())->shiftTemplates()));
    }

    /** The template whose hours cover this moment (yesterday's evening may run past midnight), or null. */
    public function coveringTemplate(?CarbonInterface $at = null, ?QueueSetting $settings = null): ?array
    {
        $at ??= now();
        $today = $this->queue->businessDate($at);
        $yesterday = Carbon::parse($today, QueueService::TZ)->subDay()->toDateString();

        foreach ([$yesterday, $today] as $date) {
            foreach ($this->templateHours($date, $settings) as $t) {
                if ($t['starts']->lte($at) && $t['ends']->gt($at)) {
                    return $t;
                }
            }
        }

        return null;
    }

    /** When the next shift starts after this moment (today's or tomorrow's templates); null without templates. */
    public function nextStart(?CarbonInterface $at = null, ?QueueSetting $settings = null): ?Carbon
    {
        $at ??= now();
        $today = $this->queue->businessDate($at);
        $tomorrow = Carbon::parse($today, QueueService::TZ)->addDay()->toDateString();

        return collect([...$this->templateHours($today, $settings), ...$this->templateHours($tomorrow, $settings)])
            ->pluck('starts')->filter(fn (Carbon $starts) => $starts->gt($at))
            ->sortBy(fn (Carbon $starts) => $starts->getTimestamp())->first();
    }

    /**
     * «بدأت شغل» (attendance §3): an active user with at least one platform, while a shift is
     * open, gets her desk on it — created, or her row of this shift reactivated (her counters
     * and attendance go on) — as `available` (`busy` while she still holds a window), an `in`
     * event, and a router pass (the lounge goes to her in order). Pressing it proves she is
     * here: a heartbeat is recorded. Already checked in: nothing changes. A shift whose time
     * came but that the tick has not opened yet is opened first.
     *
     * @throws AttendanceRefused `disabled`, `no_platforms`, `shift_not_open` (with `time`), `no_shift`
     */
    public function checkIn(User $user): ShiftMember
    {
        $s = QueueSetting::current();

        if (! $s->enabled) {
            throw new AttendanceRefused('disabled', 409);
        }

        if (! self::mayCheckIn($user)) {
            throw new AttendanceRefused('no_platforms', 403);
        }

        $shift = $this->queue->openShift();

        if ($shift === null && $this->coveringTemplate(null, $s) !== null) {
            $this->transition($s);
            $shift = $this->queue->openShift();
        }

        if ($shift === null) {
            $next = $this->nextStart(null, $s);

            throw $next === null
                ? new AttendanceRefused('no_shift', 409)
                : new AttendanceRefused('shift_not_open', 409, ['time' => $next->copy()->setTimezone(QueueService::TZ)->format('H:i')]);
        }

        $this->presence->heartbeat($user, 'web');

        try {
            [$desk, $changed] = DB::transaction(fn () => $this->checkInLocked($shift, $user), attempts: 3);
        } catch (UniqueConstraintViolationException) {
            // A second click raced the first one: her desk exists now.
            $desk = ShiftMember::query()->where('shift_id', $shift->id)->where('user_id', $user->id)->firstOrFail();
            $changed = false;
        }

        if ($changed) {
            $this->changed($desk, 'بدأت شغل');
        }

        return $desk->fresh(['user', 'shift']);
    }

    /** @return array{0: ShiftMember, 1: bool} her desk, and whether it changed */
    private function checkInLocked(Shift $shift, User $user): array
    {
        $desk = ShiftMember::query()->where('shift_id', $shift->id)->where('user_id', $user->id)->lockForUpdate()->first();

        if ($desk !== null && $desk->status !== 'left') {
            return [$desk, false];
        }

        $attrs = [
            'status' => app(QueueRouter::class)->openForUser((int) $user->id)->exists() ? 'busy' : 'available',
            'left_at' => null, 'requested_by_id' => null, 'break_started_at' => null, 'break_ends_at' => null, 'break_overrun_alerted_at' => null,
        ];

        if ($desk === null) {
            $desk = ShiftMember::query()->create(['shift_id' => $shift->id, 'user_id' => $user->id, 'joined_at' => now()] + $attrs);
        } else {
            $desk->update($attrs);
        }

        $desk->setRelation('shift', $shift);
        $this->attendance->record($desk, 'in');

        return [$desk, true];
    }

    /**
     * «خروج» (attendance §3) — by her, by the leader or a supervisor on her behalf, or by logging
     * out. No open window: she leaves at once (`out`). Otherwise `checking_out`: no new chats, she
     * finishes what she has and leaves when her last window closes (`settle()`), or sends them
     * back to the lounge (`handBack()`). Returns her status afterwards (`left` or `checking_out`).
     */
    public function checkOut(ShiftMember $m, ?User $by = null): string
    {
        $by = $this->actor($m, $by);

        $result = DB::transaction(function () use ($m, $by) {
            $locked = ShiftMember::query()->with('shift')->lockForUpdate()->find($m->id);

            if ($locked === null || $locked->status === 'left') {
                return null;
            }

            if (app(QueueRouter::class)->openForUser((int) $locked->user_id)->exists()) {
                if ($locked->status === 'checking_out') {
                    return ['checking_out', false];
                }

                $locked->update(['status' => 'checking_out', 'requested_by_id' => $by?->id]);

                return ['checking_out', true];
            }

            $this->leaveLocked($locked, 'out', $by);

            return ['left', true];
        }, attempts: 3);

        if ($result === null) {
            return 'left';
        }

        [$status, $changed] = $result;

        if ($status === 'left') {
            $this->releaseReserved($m);
        }

        if ($changed) {
            $this->changed($m, $status === 'left' ? 'خروج' : 'بتقفل');
        }

        return $status;
    }

    /**
     * «رجّعي شبابيكي للصالة» (attendance §3): while she is checking out, every open window of hers
     * goes back to the lounge with its ticket, at the top (the transfer path: close reason
     * `transfer`, never `no_reply`, no penalty), and then she leaves. Her `out` is credited to
     * whoever pressed the hand-back (null when she did it herself), whoever pressed «خروج».
     * Returns how many went back; 0 when she is not checking out.
     */
    public function handBack(ShiftMember $m, ?User $by = null): int
    {
        $m->loadMissing('user');

        if ($m->fresh()?->status !== 'checking_out') {
            return 0;
        }

        // The presser, recorded before the transfers by a plain conditional update (no lock is
        // held while the transfers take the conversation → entry → member locks). Its row count
        // is not used: MySQL reports 0 when the value was already the same.
        ShiftMember::query()->whereKey($m->id)->where('status', 'checking_out')
            ->update(['requested_by_id' => $this->actor($m, $by)?->id]);

        $n = 0;

        foreach (app(QueueRouter::class)->openForUser((int) $m->user_id)->get() as $e) {
            $n += (int) (app(WindowLifecycle::class)->transferAway($e, 'خروج '.$m->user?->name) !== null);
        }

        // Each transfer settles her after its commit; this covers a window that closed on its own meanwhile.
        $this->settle($m);

        return $n;
    }

    /** Logging out of the CRM = «خروج» on every desk she holds on an open shift (attendance §3). */
    public function checkOutEverywhere(User $user): void
    {
        // A plain read: logging out never creates the settings row.
        if (! (bool) QueueSetting::query()->find(1)?->enabled) {
            return;
        }

        ShiftMember::query()->with('user')->where('user_id', $user->id)->where('status', '!=', 'left')
            ->whereHas('shift', fn ($q) => $q->where('status', 'open'))->get()
            ->each(fn (ShiftMember $m) => $this->checkOut($m));
    }

    /**
     * Her pending step, once she has no open window left (per user, across her shift rows):
     * `pending_break` → `break`, `checking_out` → `left` (`out`, by whoever asked for it).
     * Called after every window close (WindowLifecycle) and by the tick as a safety net.
     */
    public function settle(ShiftMember $m): void
    {
        $result = DB::transaction(function () use ($m) {
            $locked = ShiftMember::query()->with('shift')->lockForUpdate()->find($m->id);

            if ($locked === null || ! in_array($locked->status, self::WAITING_FOR_WINDOWS, true)
                || app(QueueRouter::class)->openForUser((int) $locked->user_id)->exists()) {
                return null;
            }

            $by = $locked->requested_by_id !== null ? User::query()->find($locked->requested_by_id) : null;

            if ($locked->status === 'pending_break') {
                $this->startBreakLocked($locked, $by, QueueSetting::current());

                return 'break';
            }

            $this->leaveLocked($locked, 'out', $by);

            return 'left';
        }, attempts: 3);

        if ($result === 'left') {
            $this->releaseReserved($m);
        }

        if ($result !== null) {
            $this->changed($m, $result === 'left' ? 'خروج' : 'استراحة');
        }
    }

    /** `settle()` every desk of hers on an open shift that waits for her windows to close. */
    public function settleUser(int $userId): void
    {
        ShiftMember::query()->with('user')->where('user_id', $userId)->whereIn('status', self::WAITING_FOR_WINDOWS)
            ->whereHas('shift', fn ($q) => $q->where('status', 'open'))->get()
            ->each(fn (ShiftMember $m) => $this->settle($m));
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

        // Its leader is the template's as it stands now: the row was made early in the day (§2).
        $template = collect($s->shiftTemplates())->firstWhere('key', $shift->shift_key);
        $shift->refresh();
        $shift->update([
            'leader_user_id' => $template !== null ? self::leaderOf($template) : $shift->leader_user_id,
            'settings_snapshot' => $s->only(['windows_per_moderator', 'silence_warn_seconds', 'silence_close_seconds', 'break_minutes', 'break_after_minutes']),
        ]);

        // Escalations still waiting from the previous shift now belong to this shift's leader.
        QueueEntry::query()->where('status', 'waiting')->where('priority', 'escalation')
            ->update(['shift_id' => $shift->id, 'reserved_user_id' => $shift->leader_user_id]);

        $this->logger->log(ActorType::System, null, ActivityLogger::SHIFT_OPEN, $shift, null, ['shift' => $shift->shift_key]);
        DB::afterCommit(fn () => SafeBroadcast::send(new ShiftUpdated($shift->fresh(['members.user', 'leader']))));
        app(QueueRouter::class)->runAfterCommit('بداية شيفت '.$shift->name);
    }

    /**
     * The templates are the single source of a shift's name, hours and leader (attendance design
     * §2). After they change (Settings → Queue, `queue:setup-team`), every shift row not yet
     * closed follows its template for the row's own date, at once:
     * - `planned`: name, `starts_at`, `ends_at` and leader (so hours moved earlier on the day
     *   let the shift open — by the tick or by the first «بدأت شغل» — at the new time);
     * - `open`: name, `ends_at` and leader (it already started; the next tick closes it when the
     *   new end has passed).
     * - `closed` today, because its end was edited below now (N1): open again when the edit is
     *   put back — see `reopenLocked()`.
     * On an open shift, the waiting escalations that were the old leader's follow the new one, and
     * the board (and, for a new leader, the router) hear about it once committed.
     */
    public function syncTemplates(): void
    {
        $s = QueueSetting::current();
        $keys = array_map(fn (array $t) => (string) $t['key'], $s->shiftTemplates());
        // `date` is cast: compare with the exact string Eloquent writes (as todayShifts() does).
        $today = (new Shift)->fromDateTime(Carbon::parse($this->queue->businessDate()));

        Shift::query()->whereIn('shift_key', $keys)
            ->where(fn ($q) => $q->whereIn('status', ['planned', 'open'])->orWhere(fn ($q) => $q->where('status', 'closed')->where('date', $today)))
            ->get()
            ->each(function (Shift $shift) use ($s) {
                $t = collect($this->templateHours($shift->date->toDateString(), $s))->firstWhere('key', $shift->shift_key);

                if ($t === null) {
                    return;
                }

                $reopened = false;

                if ($shift->status === 'closed') {
                    if (! $this->reopenLocked($shift, $t['ends'])) {
                        return;
                    }

                    $reopened = true;
                }

                $old = $shift->leader_user_id !== null ? (int) $shift->leader_user_id : null;
                $new = $t['leader_user_id'];
                // Stored in UTC like every other shift time (Eloquent writes a Carbon in its own zone).
                $shift->fill(['name' => $t['name'], 'ends_at' => $t['ends']->copy()->utc(), 'leader_user_id' => $new]);

                if ($shift->status === 'planned') {
                    $shift->starts_at = $t['starts']->copy()->utc();
                }

                if (! $shift->isDirty() && ! $reopened) {
                    return;
                }

                $shift->save();

                if ($shift->status !== 'open') {
                    return;
                }

                DB::afterCommit(fn () => SafeBroadcast::send(new ShiftUpdated($shift->fresh(['members.user', 'leader']))));

                if ($reopened) {
                    $this->logger->log(ActorType::System, null, ActivityLogger::SHIFT_OPEN, $shift, null, ['shift' => $shift->shift_key, 'reopened' => true]);
                    app(QueueRouter::class)->runAfterCommit('رجوع شيفت '.$shift->name);
                }

                if ($old === $new) {
                    return;
                }

                QueueEntry::query()->where('status', 'waiting')->where('priority', 'escalation')->where('shift_id', $shift->id)
                    ->when($old === null, fn ($q) => $q->whereNull('reserved_user_id'), fn ($q) => $q->where('reserved_user_id', $old))
                    ->update(['reserved_user_id' => $new]);

                app(QueueRouter::class)->runAfterCommit('ليدر جديد لشيفت '.$shift->name);
            });
    }

    /**
     * N1: today's shift was closed because its end was edited below now, and the edit is put back.
     * It opens again (`closed_at` cleared, `opened_at` kept) only when the template's end is now in
     * the future, it was closed before that end, and no other shift of the day opened after it
     * (the next shift took over). Claimed by a conditional update, like `open()` and `close()`.
     * Whoever `close()` checked out (`auto_out`) stays out: she checks in again with «بدأت شغل».
     */
    private function reopenLocked(Shift $shift, Carbon $ends): bool
    {
        if ($shift->opened_at === null || $shift->closed_at === null || ! $ends->isFuture() || ! $shift->closed_at->lt($ends)) {
            return false;
        }

        $newer = Shift::query()->whereKeyNot($shift->id)->where('date', $shift->getRawOriginal('date'))
            ->whereNotNull('opened_at')->where('opened_at', '>=', $shift->opened_at)->exists();

        if ($newer) {
            return false;
        }

        $claimed = Shift::query()->whereKey($shift->id)->where('status', 'closed')->update(['status' => 'open', 'closed_at' => null]);

        if ($claimed !== 1) {
            return false;
        }

        $shift->refresh();

        return true;
    }

    /** @param  array<string, mixed>  $template */
    private static function leaderOf(array $template): ?int
    {
        return isset($template['leader_user_id']) ? (int) $template['leader_user_id'] : null;
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

    /**
     * The shift is over: whoever is still checked in leaves (`auto_out`, attendance §3); her open
     * windows stay with her (Part 1: she finishes them) and her unfinished reserved overnight
     * customers go back to the pool. Claimed by a conditional update, like `open()`.
     */
    private function close(Shift $shift): void
    {
        $claimed = Shift::query()->whereKey($shift->id)->where('status', 'open')->update(['status' => 'closed', 'closed_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        foreach ($shift->members()->with('user')->where('status', '!=', 'left')->get() as $m) {
            $this->leave($m, 'auto_out');
        }

        DB::afterCommit(fn () => SafeBroadcast::send(new ShiftUpdated($shift->fresh(['members.user', 'leader']))));
    }

    /** Her unfinished reserved (overnight) entries go back to the shared pool. Never under a member lock. */
    private function releaseReserved(ShiftMember $m): void
    {
        QueueEntry::query()->where('reserved_user_id', $m->user_id)->where('status', 'waiting')->where('priority', 'overnight')
            ->update(['reserved_user_id' => null]);
    }

    /**
     * «استراحة» / «رجعت» (by her, or on her behalf from the board) and `offline` (the tick),
     * decided under the member's row lock (the router locks it last when it assigns, so the two
     * never cross: a break asked while a customer is being given to her sees that window).
     *
     * - `break`: at once (a `break` event) when she holds no open window (per USER, across her
     *   shift rows), else `pending_break` (no new chats; `settle()` starts it when her last window
     *   closes). The break lasts until she presses «رجعت»: `break_ends_at` only marks where the
     *   overrun starts (`break_minutes`). Asking again while on a break changes nothing.
     * - `available`: back from a break (a `back` event), a pending break cancelled, back online, or
     *   a check-out cancelled («رجعت» while `checking_out`, by her or from the board: `busy` while
     *   she holds a window, no attendance event — she never left).
     * - `offline` (the tick): never over a break or a pending break (she keeps it; the hand-off
     *   of her windows lets `settle()` start it), nor over a check-out.
     * - A member who left is not changed; one who is checking out only by `available`.
     */
    public function setStatus(ShiftMember $m, string $status, ?User $by = null): void
    {
        if (! in_array($status, ['available', 'break', 'offline'], true)) {
            throw new \InvalidArgumentException('Unknown member status: '.$status);
        }

        $s = QueueSetting::current();
        $by = $this->actor($m, $by);

        $changed = DB::transaction(function () use ($m, $status, $s, $by) {
            // The first statement is the locking read: the open-window count below then sees
            // whatever an assignment committed while we waited for the row.
            $locked = ShiftMember::query()->with('shift')->lockForUpdate()->find($m->id);

            if ($locked === null || $locked->status === 'left' || ($locked->status === 'checking_out' && $status !== 'available')) {
                return false;
            }

            $open = app(QueueRouter::class)->openForUser((int) $locked->user_id)->exists();

            if ($status === 'break') {
                if ($locked->status === 'break' || ($open && $locked->status === 'pending_break')) {
                    return false;
                }

                if ($open) {
                    $locked->update(['status' => 'pending_break', 'requested_by_id' => $by?->id]);
                } else {
                    $this->startBreakLocked($locked, $by, $s);
                }

                return true;
            }

            if ($status === 'available') {
                if ($locked->status === 'break') {
                    $this->attendance->record($locked, 'back', $by);
                }

                $locked->update(['status' => $open ? 'busy' : 'available', 'break_ends_at' => null, 'requested_by_id' => null]);

                return true;
            }

            if (in_array($locked->status, ['offline', 'break', 'pending_break'], true)) {
                return false;
            }

            $locked->update(['status' => 'offline']);

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
     * Scheduled (every tick), per checked-in desk of an open shift:
     * - on a break: nothing ends it but her «رجعت» (or the leader's); past `break_ends_at` the
     *   leader hears once (`queue.break_overrun`);
     * - heartbeats: offline after 3 minutes (no new chats), her open windows (all of them, per
     *   user) handed on after 5, checked out after 10 (`auto_out`); a desk that is checking out
     *   keeps «بتقفل» while offline, and a pending break stays pending (the hand-off starts it);
     * - back online: `available` again;
     * - a pending break or check-out whose windows are all closed is settled (safety net).
     *
     * Not arrived (flow revision §2): a desk never seen since it was expected is left out of the
     * safeguard below (it is checked out at once: no heartbeat at all counts as forever).
     *
     * Mass-offline safeguard: when in one tick every serving desk that had arrived would turn
     * offline (two or more of them), or more than half of them with at least 3, the heartbeats
     * are what failed (Reverb, the network, the office), not the moderators. Then none of them is
     * marked offline, handed off or checked out: a warning is logged, supervisors are told (at
     * most every 15 minutes) and the next tick looks again.
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
            if ($m->status === 'break') {
                if ($m->break_ends_at !== null && $m->break_ends_at->lte(now()) && $m->break_overrun_alerted_at === null) {
                    $this->alertBreakOverrun($m, $s);
                }

                continue;
            }

            $away = $offlineFor($m);
            $absent = self::notArrived($m);

            if ($absent || $away >= self::OFFLINE_AFTER_SECONDS) {
                if ($mass && ! $absent) {
                    continue; // the safeguard: not offline, no hand-off, no check-out; try again next tick
                }

                if ($away >= self::REASSIGN_AFTER_SECONDS) {
                    foreach ($router->openForUser((int) $m->user_id)->get() as $e) {
                        app(WindowLifecycle::class)->transferAway($e, 'offline');
                    }
                    $m->refresh();
                }

                if ($away >= self::AUTO_OUT_AFTER_SECONDS) {
                    $this->leave($m, 'auto_out');

                    continue;
                }

                // Not over a break the hand-off above just started (her last window left), nor over
                // a pending break: it stays, and settle() starts it once her windows are handed on.
                if (! in_array($m->status, ['offline', 'checking_out', 'left', 'break', 'pending_break'], true)) {
                    $this->setStatus($m, 'offline');
                }

                continue;
            }

            if ($m->status === 'offline') {
                $this->backOnlineLocked($m);
                $m->refresh();
            }

            if (in_array($m->status, self::WAITING_FOR_WINDOWS, true) && ! $router->openForUser((int) $m->user_id)->exists()) {
                $this->settle($m);
            }
        }
    }

    /**
     * Her heartbeat is back (N2): `available` again — `busy` while she holds a window — but only
     * when her row, locked, is still `offline`. The tick read her a moment ago; a «خروج» (or
     * anything else) that committed since then wins. Not `setStatus('available')`: that one also
     * cancels a check-out, which «رجعت» needs and the tick must never do.
     */
    private function backOnlineLocked(ShiftMember $m): void
    {
        $changed = DB::transaction(function () use ($m) {
            $locked = ShiftMember::query()->lockForUpdate()->find($m->id);

            if ($locked === null || $locked->status !== 'offline') {
                return false;
            }

            $open = app(QueueRouter::class)->openForUser((int) $locked->user_id)->exists();
            $locked->update(['status' => $open ? 'busy' : 'available', 'break_ends_at' => null, 'requested_by_id' => null]);

            return true;
        }, attempts: 3);

        if ($changed) {
            $this->changed($m, 'رجوع أونلاين');
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

    /**
     * Once per break (attendance §3): she is past `break_minutes`. The leader of her shift is
     * told; the active supervisors and admins instead when the shift has no active leader or it
     * is the leader's own break (never the member herself). The claim on her row makes two
     * overlapping ticks tell once; a new break clears it.
     */
    private function alertBreakOverrun(ShiftMember $m, QueueSetting $s): void
    {
        $claimed = ShiftMember::query()->whereKey($m->id)->where('status', 'break')->whereNull('break_overrun_alerted_at')->toBase()
            ->update(['break_overrun_alerted_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $data = [
            'member_id' => $m->id, 'user_id' => (int) $m->user_id, 'name' => $m->user->name, 'shift' => $m->shift?->name,
            'minutes' => (int) $s->break_minutes, 'since' => $m->break_started_at?->toIso8601String(),
        ];
        $leaderId = $m->shift?->leader_user_id !== null ? (int) $m->shift->leader_user_id : null;
        $leader = $leaderId !== null && $leaderId !== (int) $m->user_id ? User::query()->where('is_active', true)->find($leaderId) : null;

        if ($leader !== null) {
            $this->notifier->notify($leader, 'queue.break_overrun', $data);

            return;
        }

        User::query()->where('is_active', true)->get()
            ->filter(fn (User $u) => $u->isSupervisorOrAbove() && (int) $u->id !== (int) $m->user_id)
            ->each(fn (User $u) => $this->notifier->notify($u, 'queue.break_overrun', $data));
    }

    /** The break starts now (a `break` event); the overrun line is `break_minutes` from now. */
    private function startBreakLocked(ShiftMember $locked, ?User $by, QueueSetting $s): void
    {
        $locked->update([
            'status' => 'break', 'break_started_at' => now(), 'break_ends_at' => now()->addMinutes((int) $s->break_minutes),
            'break_overrun_alerted_at' => null, 'requested_by_id' => null,
        ]);
        $this->attendance->record($locked, 'break', $by);
    }

    /** `left` with its attendance event, on a row the caller holds locked (her reservations are released after the commit). */
    private function leaveLocked(ShiftMember $locked, string $event, ?User $by): void
    {
        $locked->update(['status' => 'left', 'left_at' => now(), 'requested_by_id' => null, 'break_ends_at' => null]);
        $this->attendance->record($locked, $event, $by);
    }

    /** She leaves whatever she holds (the shift closed, or 10 minutes offline). False when she had already left. */
    private function leave(ShiftMember $m, string $event, ?User $by = null): bool
    {
        $done = DB::transaction(function () use ($m, $event, $by) {
            $locked = ShiftMember::query()->with('shift')->lockForUpdate()->find($m->id);

            if ($locked === null || $locked->status === 'left') {
                return false;
            }

            $this->leaveLocked($locked, $event, $by);

            return true;
        }, attempts: 3);

        if ($done) {
            $this->releaseReserved($m);
            $this->changed($m, 'خروج');
        }

        return $done;
    }

    /** Her desk on the board and on her own channel once committed, then a router pass. */
    private function changed(ShiftMember $m, string $trigger): void
    {
        $id = $m->id;
        $m->loadMissing('user');

        DB::afterCommit(function () use ($id) {
            if ($desk = ShiftMember::query()->with('user')->find($id)) {
                SafeBroadcast::send(new QueueMemberUpdated($desk));
            }
        });
        app(QueueRouter::class)->runAfterCommit($trigger.' '.$m->user?->name);
    }

    /** Who acted, for the attendance log: null when she did it herself. */
    private function actor(ShiftMember $m, ?User $by): ?User
    {
        return $by !== null && (int) $by->id !== (int) $m->user_id ? $by : null;
    }
}
