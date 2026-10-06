<?php

namespace App\Queue;

use App\Analytics\PresenceTracker;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Http\Resources\QueueEntryResource;
use App\Http\Resources\ShiftMemberResource;
use App\Http\Support\DateRange;
use App\Models\Conversation;
use App\Models\QueueDecision;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything the live board draws, read in one go: the open shift and its desks, the lounge,
 * the open windows, the router's last decisions and today's numbers.
 *
 * The number of queries does not depend on how many customers wait or how many desks are
 * open: relations are eager loaded, counters come from grouped queries, and the settings are
 * read once and handed down. The lounge is cut at WAITING_LIMIT (what the room can seat);
 * `kpis.waiting` is the real count.
 */
class BoardState
{
    /** Twelve cards and forty-two heads: what the lounge seats. The rest is a number. */
    public const WAITING_LIMIT = 54;

    public const DECISIONS = 20;

    /** Customers who wrote to the bot this recently are shown at the reception. */
    public const RECEPTION_MINUTES = 15;

    public function __construct(
        private readonly QueueService $queue,
        private readonly PresenceTracker $presence,
        private readonly ShiftService $shifts,
        private readonly Attendance $attendance,
        private readonly RatingStats $ratings,
    ) {}

    /** The board with the queue switched off: nothing but the fact. Reads the settings row only. */
    public static function disabled(): array
    {
        return ['enabled' => false, 'now' => now()->toIso8601String()];
    }

    /** @return array<string, mixed> */
    public function snapshot(?QueueSetting $settings = null): array
    {
        $s = $settings ?? QueueSetting::current();

        if (! $s->enabled) {
            return self::disabled();
        }

        $date = $this->queue->businessDate();
        $stored = (new Shift)->fromDateTime(Carbon::parse($date));

        $open = $this->queue->openShift();
        $shifts = Shift::query()->where('date', $stored)->orderBy('starts_at')->get();

        if ($open !== null && ! $shifts->contains('id', $open->id)) {
            $shifts->prepend($open); // a shift of yesterday's date that runs past midnight
        }

        $shifts->load('leader');
        $members = $shifts->isEmpty() ? collect() : ShiftMember::query()->with('user.userPlatforms')
            ->whereIn('shift_id', $shifts->pluck('id'))->where('status', '!=', 'left')->orderBy('id')->get();

        $windows = QueueEntry::query()->with('conversation.customer')->whereIn('status', QueueEntry::OPEN_STATUSES)
            ->orderBy('window_no')->orderBy('id')->get();
        $lounge = QueueEntry::query()->with('conversation.customer')->where('status', 'waiting')
            ->orderByRaw("CASE priority WHEN 'returning' THEN 0 WHEN 'escalation' THEN 1 WHEN 'overnight' THEN 3 ELSE 2 END")
            ->orderBy('enqueued_at')->orderBy('id')->limit(self::WAITING_LIMIT)->get();

        $desks = $members->where('shift_id', $open?->id)->values();
        // Ratings of the Cairo day (G11), numbers only: one summary and one grouped query whatever the desks.
        $ratingsByUser = $this->ratings->byAgent(DateRange::startOfCairoDay($date), now());

        return [
            'enabled' => true,
            'now' => now()->toIso8601String(),
            'business_date' => $date,
            'shift' => $open ? $this->shift($open) : null,
            // No shift open (attendance §2, shifts run by the clock): its hours came and the next
            // tick opens it, or when the next one starts.
            'shift_opening' => $open === null && $this->shifts->coveringTemplate(null, $s) !== null,
            'next_shift_starts_at' => $open === null ? $this->shifts->nextStart(null, $s)?->toIso8601String() : null,
            'shifts' => $shifts->map(fn (Shift $sh) => $this->shift($sh) + [
                'member_user_ids' => $members->where('shift_id', $sh->id)->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all(),
            ])->values()->all(),
            'members' => $this->desks($desks, $windows, $open, $s, $ratingsByUser),
            'waiting' => $lounge->map(fn (QueueEntry $e) => QueueEntryResource::data($e, $s))->values()->all(),
            'open' => $windows->map(fn (QueueEntry $e) => QueueEntryResource::data($e, $s))->values()->all(),
            'decisions' => $this->decisions(),
            'last_call' => $this->lastCall($stored),
            'kpis' => $this->kpis($stored, $windows, $desks, $s, $date),
            'reception' => ['with_bot' => $this->withBot()],
            'settings' => [
                'windows_per_moderator' => (int) $s->windows_per_moderator,
                'silence_warn_seconds' => (int) $s->silence_warn_seconds,
                'silence_close_seconds' => (int) $s->silence_close_seconds,
                'sla_first_reply_seconds' => (int) $s->sla_first_reply_seconds,
                'sla_target_pct' => (int) $s->sla_target_pct,
                'break_minutes' => (int) $s->break_minutes,
            ],
            'templates' => $this->templates($s, $shifts),
            'users' => $this->users(),
        ];
    }

    /** @return array<string, mixed> */
    private function shift(Shift $sh): array
    {
        $leader = $sh->leader;

        return [
            'id' => $sh->id,
            'date' => $sh->date?->toDateString(),
            'shift_key' => $sh->shift_key,
            'name' => $sh->name,
            'status' => $sh->status,
            'starts_at' => $sh->starts_at?->toIso8601String(),
            'ends_at' => $sh->ends_at?->toIso8601String(),
            'opened_at' => $sh->opened_at?->toIso8601String(),
            'leader' => $leader ? ['id' => $leader->id, 'name' => $leader->name, 'color' => $leader->color] : null,
        ];
    }

    /**
     * The desks of the open shift. Windows and load are counted per USER (a window she got in
     * the morning shift is still hers in the evening), the day's counters per desk.
     * Each desk carries her attendance of the day (first in, last out, worked, breaks).
     *
     * @param  Collection<int, ShiftMember>  $desks
     * @param  Collection<int, QueueEntry>  $windows
     * @return list<array<string, mixed>>
     */
    private function desks(Collection $desks, Collection $windows, ?Shift $open, QueueSetting $s, array $ratingsByUser): array
    {
        if ($desks->isEmpty()) {
            return [];
        }

        $counts = QueueEntry::query()->toBase()->whereIn('shift_member_id', $desks->pluck('id'))
            ->selectRaw('shift_member_id, close_reason, COUNT(*) as n')->groupBy('shift_member_id', 'close_reason')->get()
            ->groupBy('shift_member_id');
        $byUser = $windows->groupBy('assigned_user_id');
        // Today's attendance of every desk, from one query (attendance §4).
        $attendance = $this->attendance->figuresFor(
            $desks->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
            // The events sit under her SHIFT's date: an evening shift running past midnight is still yesterday's.
            $open?->date->toDateString() ?? $this->queue->businessDate(),
            (int) $s->break_minutes,
        );

        return $desks->map(function (ShiftMember $m) use ($counts, $byUser, $open, $s, $attendance, $ratingsByUser) {
            $rows = $counts->get($m->id, collect());
            $mine = $byUser->get($m->user_id, collect())
                ->map(fn (QueueEntry $e) => ShiftMemberResource::window($e, $s))->values()->all();
            $user = $m->user;

            return ShiftMemberResource::shape(
                $m,
                $mine,
                ShiftMemberResource::today((int) $rows->sum('n'), $rows->whereNotNull('close_reason')->pluck('n', 'close_reason')->all()),
                (int) ($m->windows_cap ?? $s->windows_per_moderator),
            ) + [
                'is_leader' => $open !== null && $open->leader_user_id !== null && (int) $open->leader_user_id === (int) $m->user_id,
                'platforms' => $user ? $this->platformsOf($user) : [],
                'attendance' => $attendance[(int) $m->user_id] ?? Attendance::EMPTY,
                'rating' => $ratingsByUser[(int) $m->user_id] ?? RatingStats::EMPTY,
            ];
        })->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function decisions(): array
    {
        return QueueDecision::query()->orderByDesc('id')->limit(self::DECISIONS)->get()
            ->map(fn (QueueDecision $d) => [
                'id' => $d->id,
                'trigger' => $d->trigger,
                'lines' => array_values($d->lines ?? []),
                'at' => $d->created_at?->toIso8601String(),
            ])->values()->all();
    }

    /** The robot's last announcement of the day: «دورك 50 · شباك 3 · ميار». */
    private function lastCall(string $stored): ?array
    {
        $e = QueueEntry::query()->with('assignee')->where('business_date', $stored)->whereNotNull('called_at')
            ->whereNotNull('assigned_user_id')->orderByDesc('called_at')->orderByDesc('id')->first();

        if ($e === null) {
            return null;
        }

        return [
            'entry_id' => $e->id,
            'ticket' => $e->ticket_no,
            'window_no' => $e->window_no,
            'user_id' => $e->assigned_user_id,
            'name' => $e->assignee?->name,
            'at' => $e->called_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, QueueEntry>  $windows
     * @param  Collection<int, ShiftMember>  $desks
     * @return array<string, mixed>
     */
    private function kpis(string $stored, Collection $windows, Collection $desks, QueueSetting $s, string $date): array
    {
        $lounge = QueueEntry::query()->toBase()->where('status', 'waiting')
            ->selectRaw('priority, COUNT(*) as n, MIN(enqueued_at) as oldest')->groupBy('priority')->get();
        $live = $lounge->where('priority', '!=', 'overnight');
        $oldest = $live->pluck('oldest')->filter()->min();

        $day = QueueEntry::query()->toBase()->where('business_date', $stored)
            ->selectRaw('close_reason, COUNT(*) as n, SUM(CASE WHEN ticket_no < 100000 THEN 1 ELSE 0 END) as tickets, SUM(CASE WHEN first_reply_at IS NOT NULL THEN 1 ELSE 0 END) as replied, SUM(CASE WHEN sla_met = 1 THEN 1 ELSE 0 END) as met')
            ->groupBy('close_reason')->get();
        $closed = [];

        foreach (QueueEntry::CLOSE_REASONS as $reason) {
            $closed[$reason] = (int) ($day->firstWhere('close_reason', $reason)->n ?? 0);
        }

        $replied = (int) $day->sum('replied');
        $serving = $desks->whereIn('status', ['available', 'busy', 'pending_break']);

        return [
            'issued' => (int) $day->sum('tickets'),
            'waiting' => (int) $lounge->sum('n'),
            'waiting_overnight' => (int) $lounge->where('priority', 'overnight')->sum('n'),
            'escalations_waiting' => (int) $lounge->where('priority', 'escalation')->sum('n'),
            'longest_wait_seconds' => $oldest ? max(0, (int) Carbon::parse($oldest, config('app.timezone'))->diffInSeconds(now())) : null,
            'open' => $windows->count(),
            'capacity' => (int) $serving->sum(fn (ShiftMember $m) => (int) ($m->windows_cap ?? $s->windows_per_moderator)),
            'sla_replied' => $replied,
            'sla_met' => (int) $day->sum('met'),
            'sla_pct' => $replied > 0 ? (int) round(100 * (int) $day->sum('met') / $replied) : null,
            'sla_target_pct' => (int) $s->sla_target_pct,
            'closed' => $closed,
            'closed_manual' => $closed['inquiry'] + $closed['problem'] + $closed['case'],
            'closed_total' => array_sum($closed),
            'rating' => $this->ratings->summary(DateRange::startOfCairoDay($date), now()),
        ];
    }

    private function withBot(): int
    {
        return Conversation::query()->where('handler', Handler::Bot)
            ->where('last_customer_message_at', '>=', now()->subMinutes(self::RECEPTION_MINUTES))->count();
    }

    /**
     * The shift templates with today's hours and what became of each (`planned`, `open`,
     * `closed`, or null when today's row does not exist yet). `opens_now` marks the one
     * covering now, else the next ahead, else the first.
     *
     * @param  Collection<int, Shift>  $shifts
     * @return list<array<string, mixed>>
     */
    private function templates(QueueSetting $s, Collection $shifts): array
    {
        $day = Carbon::parse($this->queue->businessDate(), QueueService::TZ)->startOfDay();
        $rows = collect($s->shiftTemplates())->map(function (array $t) use ($day, $shifts) {
            [$fh, $fm] = array_map('intval', explode(':', (string) $t['from']) + [0, 0]);
            [$th, $tm] = array_map('intval', explode(':', (string) $t['to']) + [0, 0]);
            $starts = $day->copy()->setTime($fh, $fm);
            $ends = $day->copy()->setTime($th, $tm);

            if ($ends->lte($starts)) {
                $ends->addDay();
            }

            $row = $shifts->first(fn (Shift $sh) => $sh->shift_key === $t['key'] && $sh->date?->toDateString() === $day->toDateString());

            return [
                'key' => (string) $t['key'],
                'name' => (string) $t['name'],
                'from' => (string) $t['from'],
                'to' => (string) $t['to'],
                'location' => (string) $t['location'],
                'leader_user_id' => $row ? $row->leader_user_id : ($t['leader_user_id'] ?? null),
                'starts_at' => $starts->toIso8601String(),
                'ends_at' => $ends->toIso8601String(),
                'status' => $row?->status,
                'shift_id' => $row?->id,
                'covers_now' => $starts->lte(now()) && $ends->gt(now()),
                'ahead' => $ends->gt(now()),
            ];
        });

        $usable = $rows->where('status', '!==', 'closed');
        $next = $usable->firstWhere('covers_now', true) ?? $usable->firstWhere('ahead', true) ?? $rows->first();

        return $rows->map(fn (array $row) => collect($row)->except(['covers_now', 'ahead'])->all() + [
            'opens_now' => $next !== null && $next['key'] === $row['key'],
        ])->values()->all();
    }

    /** Everybody who can sit at a desk: the active users, with the platforms each may serve. */
    private function users(): array
    {
        return User::query()->with('userPlatforms')->where('is_active', true)->inboxStaff()->orderBy('name')->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $u->role?->value,
                'color' => $u->color,
                'platforms' => $this->platformsOf($u),
                'online' => $this->presence->isOnline($u),
            ])->values()->all();
    }

    /** @return list<string> */
    private function platformsOf(User $u): array
    {
        $platforms = $u->isSupervisorOrAbove() ? Platform::cases() : $u->platforms();

        return array_values(array_map(fn (Platform $p) => $p->value, $platforms));
    }
}
