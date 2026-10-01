<?php

namespace App\Queue;

use App\Analytics\ActivityLogger;
use App\Analytics\PresenceTracker;
use App\Enums\ActorType;
use App\Enums\Platform;
use App\Events\ConversationUpdated;
use App\Inbox\UserNotifier;
use App\Models\QueueDecision;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Queue\Events\QueueAssigned;
use App\Queue\Events\QueueEntryUpdated;
use App\Queue\Events\QueueMemberUpdated;
use App\Queue\Events\RouterDecided;
use App\Queue\Jobs\SendQueueMessage;
use App\Support\SafeBroadcast;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hands waiting entries to free moderator windows, one pass per trigger (enqueue, join, leave,
 * break end, window close…). Order of a pass:
 *   0) returning (أولوية) → her case owner, else the same moderator, when she has a free window; else
 *      the least loaded;
 *   0b) escalations → the shift leader (or any supervisor on the shift) who may serve the
 *       platform, else they wait and supervisors are alerted once;
 *   1) live (and manual) customers, oldest first → her case owner when free, else the least
 *      loaded moderator;
 *   2) the overnight backlog → each moderator drains her own reserved share into her free
 *      windows; orphans (no reservation, or the reserved moderator is off / on break / gone)
 *      and case customers whose owner is busy go to anyone.
 * The case owner is the moderator who opened the customer's open support case (flow revision
 * §6); like every preference she is used only while logged in with a free window.
 * Load (open windows, window numbers) is counted per USER, not per shift-member row, so a
 * moderator working both shifts (or the leader) keeps her windows across the handover.
 * Every pass that found somebody waiting writes one `queue_decisions` row (the board's decision
 * lines); an empty lounge (most of the 30-second ticks) writes nothing.
 *
 * The leader of the open shift is never given a live, returning or overnight customer: her desk
 * serves escalations, and whatever the board assigns her by hand. With only the leader on the
 * shift, live customers wait.
 *
 * Locks: passes are serialised by the `queue:router` cache lock (30 s). The pass itself reads the
 * lounge without row locks; each assignment is its own short transaction that locks the
 * conversation and then the entry of that ONE customer (`assign()`), so the inbound messages of
 * everybody else in the lounge are never held up by the router. The notifications and real-time
 * pushes of a pass are collected and sent once the cache lock is released, so a slow broadcaster
 * never keeps the lock (and the next pass) waiting.
 */
class QueueRouter
{
    public const ESCALATION_ALERT_MINUTES = 15;

    /** The router's cache lock: longer than any sane pass; released in `finally` anyway. */
    public const LOCK_SECONDS = 30;

    /** @var list<Closure>|null pushes of the running pass, sent after the lock is released; null outside a pass */
    private ?array $outbox = null;

    public function __construct(
        private readonly QueueService $queue,
        private readonly UserNotifier $notifier,
        private readonly ActivityLogger $logger,
        private readonly PresenceTracker $presence,
    ) {}

    /** Routes once the surrounding transaction (if any) has committed; a router failure never breaks the caller. */
    public function runAfterCommit(string $trigger): void
    {
        DB::afterCommit(fn () => rescue(fn () => $this->run($trigger), 0, report: true));
    }

    /** @return int assignments made */
    public function run(string $trigger): int
    {
        $setting = QueueSetting::current();

        if (! $setting->enabled) {
            return 0;
        }

        $shift = $this->queue->openShift();

        if ($shift === null) {
            return 0;
        }

        // Wait briefly for a concurrent pass instead of dropping this trigger: an entry enqueued
        // while another pass runs would otherwise sit until the next, unrelated trigger.
        $lock = Cache::lock('queue:router', self::LOCK_SECONDS);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            Log::warning('queue.router_lock_timeout', ['trigger' => $trigger]);

            return 0;
        }

        $this->outbox = [];

        try {
            return $this->pass($shift, $setting, $trigger);
        } finally {
            $lock->release();
            $pushes = $this->outbox;
            $this->outbox = null;

            foreach ($pushes as $push) {
                rescue($push, null, report: true);
            }
        }
    }

    /**
     * Notifications and broadcasts: once the surrounding transaction committed, and while a pass
     * is running only after its lock is released (collected in the outbox).
     */
    private function push(Closure $push): void
    {
        DB::afterCommit(function () use ($push) {
            if ($this->outbox !== null) {
                $this->outbox[] = $push;

                return;
            }

            $push();
        });
    }

    /** Open (called / active) entries assigned to this user, whatever shift-member row they hang on. */
    public function openForUser(int $userId): Builder
    {
        return QueueEntry::query()->where('assigned_user_id', $userId)->whereIn('status', QueueEntry::OPEN_STATUSES);
    }

    /**
     * Is there, right now, another moderator who could take this customer: on the open shift,
     * serving (available / busy), logged in, allowed on her platform, with a free window — never
     * the current assignee and never the leader (her desk takes escalations only). The reply
     * clock hands a window off only then (flow revision §4.3). Plain reads, no locks.
     */
    public function hasFreeDeskFor(QueueEntry $e, ?QueueSetting $setting = null): bool
    {
        $setting ??= QueueSetting::current();
        $shift = $this->queue->openShift();
        $platform = $e->conversation?->platform;

        if ($shift === null || $platform === null) {
            return false;
        }

        $exclude = array_values(array_filter([(int) $e->assigned_user_id, (int) $shift->leader_user_id]));

        return $shift->members()->with('user.userPlatforms')->whereIn('status', ['available', 'busy'])
            ->when($exclude !== [], fn ($q) => $q->whereNotIn('user_id', $exclude))->get()
            ->contains(fn (ShiftMember $m) => $m->user !== null && $m->user->is_active && $this->presence->isOnline($m->user)
                && $m->user->canAccessPlatform($platform)
                && $this->openForUser((int) $m->user_id)->count() < $this->capOf($m, $setting));
    }

    private function pass(Shift $shift, QueueSetting $setting, string $trigger): int
    {
        // A plain read: whoever is picked is locked (and checked again) one by one in assign().
        $waiting = QueueEntry::query()->with(['conversation.customer', 'reopenedFrom:id,assigned_user_id,close_reason', 'openCase:id,opened_by_id'])->where('status', 'waiting')->orderBy('enqueued_at')->orderBy('id')->get()
            ->filter(fn (QueueEntry $e) => $e->conversation !== null);

        if ($waiting->isEmpty()) {
            return 0;
        }

        $lines = ['<b>المحفّز:</b> '.e($trigger)];
        // Serving desks, then those whose moderator is logged in and active: only they take customers.
        $serving = $shift->members()->with('user.userPlatforms')->whereIn('status', ['available', 'busy'])->get()
            ->each(fn (ShiftMember $m) => $m->setRelation('shift', $shift))
            ->filter(fn (ShiftMember $m) => $m->user !== null)
            ->values();
        $members = $serving->filter(fn (ShiftMember $m) => (bool) $m->user->is_active && $this->presence->isOnline($m->user))->values();
        $lines[] = '<b>الشيفت:</b> '.$members->count().' موظفات · في الصالة '.$waiting->count();
        // The leader's desk serves escalations (and manual assignments from the board) only.
        $leaderId = $shift->leader_user_id !== null ? (int) $shift->leader_user_id : null;
        $lounge = $members->reject(fn (ShiftMember $m) => (int) $m->user_id === $leaderId)->values();

        // Nobody can take a live customer: say why, right under the shift line (flow revision §2).
        if ($lounge->isEmpty()) {
            $lines[] = '<span class="no">'.e($this->nobodyReason($serving, $members, $leaderId)).'</span>';
        }

        // Per-user open windows, snapshotted once (passes are serialised) and kept current as we assign.
        $load = $members->mapWithKeys(fn (ShiftMember $m) => [$m->user_id => $this->openForUser($m->user_id)->count()])->all();
        $capOf = fn (ShiftMember $m) => $this->capOf($m, $setting);
        // By reference: arrow functions would freeze $load at creation and never see an assignment.
        $open = function (ShiftMember $m) use (&$load, $capOf): bool {
            return $load[$m->user_id] < $capOf($m);
        };
        $loadOf = function (ShiftMember $m) use (&$load): int {
            return $load[$m->user_id];
        };
        $anyOpen = fn () => $lounge->contains(fn (ShiftMember $m) => $open($m));
        // False when she left the lounge since the read above (cancelled, resolved, taken by hand),
        // the moderator was refused under the lock, or the assignment threw: the window stays free
        // for the next customer.
        $give = function (QueueEntry $e, ShiftMember $m, string $rule, string $key) use (&$load, &$lines, $setting): bool {
            try {
                $assigned = $this->assign($e, $m, $rule, $key, $setting);
            } catch (Throwable $ex) {
                // Something about THIS customer (a bad row, a listener, a constraint): she is
                // skipped for this pass and tried again on the next one. The moderator is fine
                // and goes on serving the customers behind her.
                report($ex);
                $lines[] = '<span class="no">#'.$e->ticket_no.': التسليم وقع، هتتجرّب في الدور الجاي</span>';

                return false;
            }

            if (! $assigned) {
                if ($e->fresh()?->status === 'waiting') {
                    // The customer is still there, so the moderator was refused under the lock:
                    // her desk is not what the snapshot said, nothing more for her in this pass.
                    // The customer waits for the next one.
                    $lines[] = '<span class="no">#'.$e->ticket_no.': '.e($m->user->name).' مبقتش متاحة، مستنية الدور الجاي</span>';
                    $load[$m->user_id] = PHP_INT_MAX;
                } else {
                    $lines[] = '<span class="no">#'.$e->ticket_no.': خرجت من الصالة قبل التسليم</span>';
                }

                return false;
            }

            $load[$m->user_id]++;
            $lines[] = $this->line($e, $m, $rule);

            return true;
        };
        // Never the moderator she was taken from for not replying (flow revision §4.3).
        $pick = fn (QueueEntry $e) => $this->choose($this->candidates(
            $e->excluded_user_id !== null ? $lounge->reject(fn (ShiftMember $m) => (int) $m->user_id === (int) $e->excluded_user_id)->values() : $lounge,
            $e->conversation->platform, $open, $setting,
        ), $loadOf);
        // Who she should go to first (flow revision §6): the moderator who opened her open case,
        // then (a returning customer) her last moderator — each only when logged in (in the
        // lounge) with a free window and allowed on her platform, never the excluded one.
        // Null: anyone.
        $preferred = function (QueueEntry $e) use ($lounge, $open, $setting): ?array {
            $candidates = [];

            if (($owner = $this->caseOwner($e, $setting)) !== null) {
                $candidates[] = [$owner, 'كيس مفتوح #'.$e->open_case_id.' ← اللي فتحته'];
            }

            if (($same = $this->sameModerator($e, $setting)) !== null) {
                $candidates[] = [$same, 'راجعة (أولوية) لنفس الموظفة'];
            }

            foreach ($candidates as [$userId, $rule]) {
                if ($e->excluded_user_id !== null && $userId === (int) $e->excluded_user_id) {
                    continue;
                }

                $m = $lounge->firstWhere('user_id', $userId);

                if ($m !== null && $open($m) && $m->user->canAccessPlatform($e->conversation->platform)) {
                    return [$m, $rule];
                }
            }

            return null;
        };
        $n = 0;

        // 0) returning → her case owner, else the same moderator, when free; else the least loaded.
        foreach ($waiting->where('priority', 'returning') as $e) {
            [$m, $rule] = $preferred($e) ?? [null, 'راجعة (أولوية) (الأقل حملاً)'];
            $m ??= $pick($e);

            if ($m === null) {
                $lines[] = '<span class="no">#'.$e->ticket_no.' راجعة: مفيش شباك فاضي</span>';

                continue;
            }

            $n += (int) $give($e, $m, $rule, 'returning');
        }

        // 0b) escalations → the leader when she may serve the platform, else any supervisor on
        //     the shift who may; nobody free → it waits and supervisors are alerted once.
        $leader = $leaderId !== null ? $members->firstWhere('user_id', $leaderId) : null;

        foreach ($waiting->where('priority', 'escalation') as $e) {
            $platform = $e->conversation->platform;
            $m = ($leader && $leader->user->canAccessPlatform($platform))
                ? ($open($leader) ? $leader : null)
                : $members->first(fn (ShiftMember $s) => $s->user->isSupervisorOrAbove() && $s->user->canAccessPlatform($platform) && $open($s));

            if ($m === null) {
                $lines[] = '<span class="no">#'.$e->ticket_no.' تصعيد مستني الليدر</span>';
                $this->alertEscalationWaiting($e);

                continue;
            }

            $n += (int) $give($e, $m, 'طابور التصعيد', 'escalation');
        }

        // 1) live (and manual), oldest first: her case owner when free, else the least loaded.
        foreach ($waiting->whereIn('priority', ['live', 'manual']) as $e) {
            if (! $anyOpen()) {
                if ($lounge->isNotEmpty()) {
                    $lines[] = '<span class="no">كل الشبابيك مليانة</span>';
                }

                break;
            }

            [$m, $rule] = $preferred($e) ?? [null, null];

            if ($m === null) {
                $m = $pick($e);

                if ($m === null) {
                    $lines[] = '<span class="no">#'.$e->ticket_no.': مفيش شباك فاضي</span>';

                    continue;
                }

                $rule = 'حيّة دلوقتي · الأقل حملاً ('.$loadOf($m).' مفتوح)';
            }

            $n += (int) $give($e, $m, $rule, 'live');
        }

        // 2) overnight: each member drains her own share into her free windows. An entry whose
        //    reserved member is not serving now (offline, on break, left) is an orphan: anyone.
        //    A case customer is not held for a busy owner (the reservation is a preference).
        foreach ($waiting->where('priority', 'overnight')->sortBy('ticket_no') as $e) {
            if (! $anyOpen()) {
                break;
            }

            $owned = $this->caseOwner($e, $setting) !== null;
            $own = $e->reserved_user_id && (int) $e->reserved_user_id !== (int) $e->excluded_user_id ? $lounge->firstWhere('user_id', $e->reserved_user_id) : null;

            if ($own !== null && ! $own->user->canAccessPlatform($e->conversation->platform)) {
                $own = null;
            }

            if ($own !== null && ! $open($own)) {
                if (! $owned) {
                    continue; // she is serving: her backlog waits for her next gap
                }

                $own = null;
            }

            if ($own !== null) {
                $m = $own;
                $rule = $owned ? 'كيس مفتوح #'.$e->open_case_id.' ← اللي فتحته' : 'معلّق من الليل في فراغ '.$m->user->name;
            } else {
                $m = $pick($e);

                if ($m === null) {
                    continue;
                }
                $rule = $e->reserved_user_id ? 'معلّق من الليل (موظفتها مش متاحة)' : 'معلّق من الليل (بدون موظفة)';
            }

            $n += (int) $give($e, $m, $rule, 'overnight');
        }

        $decision = QueueDecision::create([
            'shift_id' => $shift->id, 'trigger' => mb_substr($trigger, 0, 120), 'lines' => array_slice($lines, 0, 8), 'created_at' => now(),
        ]);
        $this->push(fn () => SafeBroadcast::send(new RouterDecided($decision)));

        return $n;
    }

    /**
     * Members with a free window who may serve this platform, preferring those under the
     * occupancy cap (if everyone is over it, the cap does not block routing).
     *
     * @param  Collection<int, ShiftMember>  $members
     * @param  callable(ShiftMember): bool  $open
     * @return Collection<int, ShiftMember>
     */
    public function candidates(Collection $members, Platform $platform, callable $open, ?QueueSetting $setting = null): Collection
    {
        $setting ??= QueueSetting::current();
        $cap = $setting->occupancy_cap_pct / 100;
        $all = $members->filter(fn (ShiftMember $m) => $open($m) && $m->user->canAccessPlatform($platform));
        $under = $all->filter(fn (ShiftMember $m) => $this->occupancy($m, $setting) <= $cap);

        return $under->isNotEmpty() ? $under->values() : $all->values();
    }

    /**
     * Least open windows (per user), then fewest entries this shift, then the one idle longest
     * (`shift_members.updated_at` as the proxy).
     *
     * @param  Collection<int, ShiftMember>  $cand
     * @param  (callable(ShiftMember): int)|null  $loadOf  open windows of a member; defaults to a per-user count
     */
    public function choose(Collection $cand, ?callable $loadOf = null): ?ShiftMember
    {
        $loadOf ??= fn (ShiftMember $m) => $this->openForUser($m->user_id)->count();

        return $cand->sortBy(fn (ShiftMember $m) => [$loadOf($m), $this->shiftCount($m), $m->updated_at?->timestamp ?? 0])->first();
    }

    /** Share of her window-time this shift spent on customers: handled + currently open, over cap × shift length. */
    public function occupancy(ShiftMember $m, ?QueueSetting $setting = null): float
    {
        $start = $m->shift->opened_at ?? $m->shift->starts_at;
        $shiftSec = max(1, (int) $start->diffInSeconds(now()));
        $handled = (int) QueueEntry::query()->where('shift_member_id', $m->id)->sum('handle_seconds');
        $openSec = (int) $m->openEntries()->get()->sum(fn (QueueEntry $e) => max(0, (int) ($e->delivered_at?->diffInSeconds(now()) ?? 0)));

        return ($handled + $openSec) / (max(1, $this->capOf($m, $setting ?? QueueSetting::current())) * $shiftSec);
    }

    private function capOf(ShiftMember $m, QueueSetting $setting): int
    {
        return (int) ($m->windows_cap ?? $setting->windows_per_moderator);
    }

    private function shiftCount(ShiftMember $m): int
    {
        return QueueEntry::query()->where('shift_member_id', $m->id)->count();
    }

    /**
     * Gives the entry to the user's lowest free window and tells everyone once committed.
     * One short transaction (retried on a deadlock) that locks the conversation, then the entry
     * then the moderator's shift-member row (the queue's lock order), and checks under the locks
     * that the customer is still waiting and that the moderator may still take her
     * (`assignable()`). False, and nothing changes, when not: the entry stays waiting for the
     * next pass (or, when the customer left, stays as it is).
     */
    public function assign(QueueEntry $e, ShiftMember $m, string $rule, string $ruleKey, ?QueueSetting $setting = null): bool
    {
        return DB::transaction(function () use ($e, $m, $rule, $ruleKey, $setting) {
            $locked = WindowLifecycle::lockBoth($e);
            $c = $locked?->conversation;

            if ($locked === null || $c === null || $locked->status !== 'waiting') {
                return false;
            }

            // Third lock, after the conversation and the entry: her desk. The pass chose her from
            // a snapshot; she may have gone on break, gone offline, left, or filled her windows since.
            $desk = ShiftMember::query()->with(['shift', 'user'])->lockForUpdate()->find($m->id);

            if ($desk === null || ! $this->assignable($desk, $setting ?? QueueSetting::current())) {
                return false;
            }

            $m->setRawAttributes($desk->getAttributes(), true);
            $m->setRelation('user', $desk->user);

            $used = $this->openForUser($m->user_id)->whereNotNull('window_no')->pluck('window_no')->map(fn ($w) => (int) $w)->all();
            $window = 1;
            while (in_array($window, $used, true)) {
                $window++;
            }

            $locked->forceFill([
                'status' => 'active', 'assigned_user_id' => $m->user_id, 'shift_member_id' => $m->id, 'shift_id' => $m->shift_id, 'window_no' => $window,
                'called_at' => now(), 'delivered_at' => now(), 'wait_seconds' => max(0, (int) $locked->enqueued_at->diffInSeconds(now())), 'rule' => mb_substr($rule, 0, 120),
                'last_customer_message_at' => $locked->last_customer_message_at ?? now(), 'reserved_user_id' => null,
                // Flow revision §4.1: she now waits for this moderator's first reply.
                'awaiting_reply_since' => now(), 'apology_sent_at' => null, 'overdue_alerted_at' => null,
            ])->save();
            $e->setRawAttributes($locked->getAttributes(), true);

            $c->forceFill(['assignee_id' => $m->user_id, 'assigned_at' => now(), 'queue_entry_id' => $locked->id])->save();
            $m->update(['status' => 'busy']);
            $user = $m->user;

            $this->logger->log(ActorType::System, null, ActivityLogger::QUEUE_ASSIGN, null, $c, ['ticket' => $locked->ticket_no, 'user_id' => $m->user_id, 'window' => $window, 'rule' => $ruleKey]);
            SendQueueMessage::dispatch($locked->id, 'queue_called', ['ticket' => $locked->ticket_no, 'name' => $user->name, 'window' => $window]);

            // The notification row and every real-time push only once the assignment is committed,
            // so a client that refetches sees it (and a rolled-back assignment notifies nobody), and
            // during a pass only once the router lock is released.
            $this->push(function () use ($locked, $m, $c, $user, $window) {
                $this->notifier->notify($user, 'queue.assigned', [
                    'entry_id' => $locked->id, 'conversation_id' => $c->id, 'ticket' => $locked->ticket_no, 'window_no' => $window,
                    'customer_name' => $c->customer?->name, 'platform' => $c->platform?->value, 'bot_summary' => $locked->bot_summary,
                ]);
                SafeBroadcast::send(new QueueAssigned($m->user_id, $locked));
                SafeBroadcast::send(new QueueEntryUpdated($locked));
                SafeBroadcast::send(new QueueMemberUpdated($m->fresh()));
                SafeBroadcast::send(new ConversationUpdated($c->fresh()));
            });

            return true;
        }, attempts: 3);
    }

    /**
     * May this desk take one more window right now: serving (available / busy, so not on break,
     * waiting for her break, offline or gone), her shift still open, her account active, and her
     * open windows (per USER) below her cap.
     */
    private function assignable(ShiftMember $m, QueueSetting $setting): bool
    {
        return in_array($m->status, ['available', 'busy'], true)
            && $m->shift?->status === 'open'
            && $m->user !== null && $m->user->is_active
            && $this->openForUser((int) $m->user_id)->count() < $this->capOf($m, $setting);
    }

    /** An escalation nobody on the shift can take: alert active supervisors, at most once per entry per 15 min. */
    private function alertEscalationWaiting(QueueEntry $e): void
    {
        $key = 'queue:esc-notified:'.$e->id;

        if (Cache::has($key)) {
            return;
        }

        $c = $e->conversation;
        $data = [
            'entry_id' => $e->id, 'conversation_id' => $c->id, 'ticket' => $e->ticket_no,
            'customer_name' => $c->customer?->name, 'platform' => $c->platform?->value, 'bot_summary' => $e->bot_summary,
        ];

        $this->push(function () use ($key, $data) {
            if (Cache::add($key, true, now()->addMinutes(self::ESCALATION_ALERT_MINUTES))) {
                $this->notifier->notifySupervisors('queue.escalation_waiting', $data);
            }
        });
    }

    private function line(QueueEntry $e, ShiftMember $m, string $rule): string
    {
        return '<b>#'.$e->ticket_no.'</b> <span class="hi">'.e($rule).'</span> ← <span class="ok">'.e($m->user->name).'</span>';
    }

    /**
     * The moderator whose open case reserved this ticket (flow revision §6): only when the switch
     * is on AND the reservation is the very moderator who opened the case. A share of the overnight
     * split (or Part 1's last moderator) that merely sits on a ticket with a case is not it.
     */
    private function caseOwner(QueueEntry $e, QueueSetting $setting): ?int
    {
        $opener = $e->openCase?->opened_by_id;

        return $setting->case_follow_owner && $e->open_case_id !== null && $opener !== null && (int) $e->reserved_user_id === (int) $opener ? (int) $opener : null;
    }

    /**
     * Her last moderator, for a returning customer (flow revision §6): Part 1's reservation unless
     * that reservation is her case owner's; then the moderator of the window she came back from.
     * Null for any other lane.
     */
    private function sameModerator(QueueEntry $e, QueueSetting $setting): ?int
    {
        if ($e->priority !== 'returning') {
            return null;
        }

        if ($this->caseOwner($e, $setting) === null) {
            return $e->reserved_user_id !== null ? (int) $e->reserved_user_id : null;
        }

        // A window closed by a transfer or a no-reply hand-off is one she was taken OUT of.
        $from = $e->reopenedFrom;

        if ($from === null || $from->assigned_user_id === null || in_array($from->close_reason, ['transfer', 'no_reply'], true)) {
            return null;
        }

        return (int) $from->assigned_user_id;
    }

    /**
     * Why no live customer can be given to anybody right now, for the decision line: nobody
     * serving but the leader (her desk takes escalations only), nobody checked in at all, or serving
     * desks whose moderators are not logged in («مش فاتحة» on the board).
     *
     * @param  Collection<int, ShiftMember>  $serving  available / busy desks of the open shift
     * @param  Collection<int, ShiftMember>  $online  the same, logged in
     */
    private function nobodyReason(Collection $serving, Collection $online, ?int $leaderId): string
    {
        $others = $serving->reject(fn (ShiftMember $m) => (int) $m->user_id === $leaderId);

        if ($others->isEmpty()) {
            return $leaderId !== null && $online->contains(fn (ShiftMember $m) => (int) $m->user_id === $leaderId)
                ? 'مفيش غير الليدر فاتحة، ومكتبها للتصعيد بس'
                : 'مفيش موظفة بدأت شغل في الشيفت';
        }

        return 'مفيش موظفة فاتحة: '.$others->count().' متاحة على اللوحة ومش فاتحة السيستم';
    }
}
