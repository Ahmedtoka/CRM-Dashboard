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
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Hands waiting entries to free moderator windows, one pass per trigger (enqueue, join, leave,
 * break end, window close…). Order of a pass:
 *   0) returning ★ → the same moderator when she has a free window, else the least loaded;
 *   0b) escalations → the shift leader (or any supervisor on the shift), else they wait;
 *   1) live (and manual) customers, oldest first → the least loaded moderator;
 *   2) the overnight backlog → each moderator drains her own reserved share into her free
 *      windows; orphans (no reservation, or the reserved moderator is off / on break / gone)
 *      go to anyone.
 * Every pass writes one `queue_decisions` row (the board's decision lines).
 */
class QueueRouter
{
    public function __construct(
        private readonly QueueService $queue,
        private readonly UserNotifier $notifier,
        private readonly ActivityLogger $logger,
        private readonly PresenceTracker $presence,
    ) {}

    /** Routes once the surrounding transaction (if any) has committed. */
    public function runAfterCommit(string $trigger): void
    {
        DB::afterCommit(fn () => $this->run($trigger));
    }

    /** @return int assignments made */
    public function run(string $trigger): int
    {
        if (! QueueSetting::current()->enabled) {
            return 0;
        }

        $shift = $this->queue->openShift();

        if ($shift === null) {
            return 0;
        }

        // Wait briefly for a concurrent pass instead of dropping this trigger: an entry enqueued
        // while another pass runs would otherwise sit until the next, unrelated trigger.
        $lock = Cache::lock('queue:router', 10);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            return 0;
        }

        try {
            return DB::transaction(fn () => $this->pass($shift, $trigger));
        } finally {
            $lock->release();
        }
    }

    private function pass(Shift $shift, string $trigger): int
    {
        $lines = ['<b>المحفّز:</b> '.e($trigger)];
        $members = $shift->members()->with('user.userPlatforms')->whereIn('status', ['available', 'busy'])->lockForUpdate()->get()
            ->each(fn (ShiftMember $m) => $m->setRelation('shift', $shift))
            ->filter(fn (ShiftMember $m) => $m->user !== null && $this->presence->isOnline($m->user))
            ->values();
        $waiting = QueueEntry::query()->with('conversation.customer')->where('status', 'waiting')->lockForUpdate()->orderBy('enqueued_at')->orderBy('id')->get()
            ->filter(fn (QueueEntry $e) => $e->conversation !== null);
        $lines[] = '<b>الشيفت:</b> '.$members->count().' موظفات · في الصالة '.$waiting->count();
        $n = 0;
        $open = fn (ShiftMember $m) => $m->openEntries()->count() < $m->cap();

        // 0) returning → the same member if she has a free window, else the least loaded.
        foreach ($waiting->where('priority', 'returning') as $e) {
            $same = $e->reserved_user_id ? $members->firstWhere('user_id', $e->reserved_user_id) : null;

            if ($same && $open($same) && $same->user->canAccessPlatform($e->conversation->platform)) {
                $rule = 'راجعة ★ لنفس الموظفة';
                $this->assign($e, $same, $rule, 'returning');
                $n++;
                $lines[] = $this->line($e, $same, $rule);

                continue;
            }

            $m = $this->choose($this->candidates($members, $e->conversation->platform, $open));

            if ($m === null) {
                $lines[] = '<span class="no">#'.$e->ticket_no.' راجعة: مفيش شباك فاضي</span>';

                continue;
            }

            $rule = 'راجعة ★ (الأقل حملاً)';
            $this->assign($e, $m, $rule, 'returning');
            $n++;
            $lines[] = $this->line($e, $m, $rule);
        }

        // 0b) escalations → the leader (or any supervisor on the shift); otherwise they wait.
        $leader = $shift->leader_user_id ? $members->firstWhere('user_id', $shift->leader_user_id) : null;
        $leader ??= $members->first(fn (ShiftMember $m) => $m->user->isSupervisorOrAbove());

        foreach ($waiting->where('priority', 'escalation') as $e) {
            if ($leader && $open($leader)) {
                $rule = 'طابور التصعيد';
                $this->assign($e, $leader, $rule, 'escalation');
                $n++;
                $lines[] = $this->line($e, $leader, $rule);
            } else {
                $lines[] = '<span class="no">#'.$e->ticket_no.' تصعيد مستني الليدر</span>';
            }
        }

        // 1) live (and manual), oldest first.
        foreach ($waiting->whereIn('priority', ['live', 'manual']) as $e) {
            $m = $this->choose($this->candidates($members, $e->conversation->platform, $open));

            if ($m === null) {
                $lines[] = '<span class="no">#'.$e->ticket_no.': مفيش شباك فاضي</span>';

                continue;
            }

            $rule = 'حيّة دلوقتي · الأقل حملاً ('.$m->openEntries()->count().' مفتوح)';
            $this->assign($e, $m, $rule, 'live');
            $n++;
            $lines[] = $this->line($e, $m, $rule);
        }

        // 2) overnight: each member drains her own share into her free windows. An entry whose
        //    reserved member is not serving now (offline, on break, left) is an orphan: anyone.
        foreach ($waiting->where('priority', 'overnight')->sortBy('ticket_no') as $e) {
            $own = $e->reserved_user_id ? $members->firstWhere('user_id', $e->reserved_user_id) : null;

            if ($own !== null && ! $own->user->canAccessPlatform($e->conversation->platform)) {
                $own = null;
            }

            if ($own !== null) {
                if (! $open($own)) {
                    continue; // she is serving: her backlog waits for her next gap
                }
                $m = $own;
                $rule = 'معلّق من الليل في فراغ '.$m->user->name;
            } else {
                $m = $this->choose($this->candidates($members, $e->conversation->platform, $open));

                if ($m === null) {
                    continue;
                }
                $rule = $e->reserved_user_id ? 'معلّق من الليل (موظفتها مش متاحة)' : 'معلّق من الليل (بدون موظفة)';
            }

            $this->assign($e, $m, $rule, 'overnight');
            $n++;
            $lines[] = $this->line($e, $m, $rule);
        }

        $decision = QueueDecision::create([
            'shift_id' => $shift->id, 'trigger' => mb_substr($trigger, 0, 120), 'lines' => array_slice($lines, 0, 8), 'created_at' => now(),
        ]);
        DB::afterCommit(fn () => SafeBroadcast::send(new RouterDecided($decision)));

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
    public function candidates(Collection $members, Platform $platform, callable $open): Collection
    {
        $cap = QueueSetting::current()->occupancy_cap_pct / 100;
        $all = $members->filter(fn (ShiftMember $m) => $open($m) && $m->user->canAccessPlatform($platform));
        $under = $all->filter(fn (ShiftMember $m) => $this->occupancy($m) <= $cap);

        return $under->isNotEmpty() ? $under->values() : $all->values();
    }

    /**
     * Least open windows, then fewest entries this shift, then the one idle longest
     * (`shift_members.updated_at` as the proxy).
     *
     * @param  Collection<int, ShiftMember>  $cand
     */
    public function choose(Collection $cand): ?ShiftMember
    {
        return $cand->sortBy(fn (ShiftMember $m) => [$m->openEntries()->count(), $this->shiftCount($m), $m->updated_at?->timestamp ?? 0])->first();
    }

    /** Share of her window-time this shift spent on customers: handled + currently open, over cap × shift length. */
    public function occupancy(ShiftMember $m): float
    {
        $start = $m->shift->opened_at ?? $m->shift->starts_at;
        $shiftSec = max(1, (int) $start->diffInSeconds(now()));
        $handled = (int) QueueEntry::query()->where('shift_member_id', $m->id)->sum('handle_seconds');
        $openSec = (int) $m->openEntries()->get()->sum(fn (QueueEntry $e) => max(0, (int) ($e->delivered_at?->diffInSeconds(now()) ?? 0)));

        return ($handled + $openSec) / (max(1, $m->cap()) * $shiftSec);
    }

    private function shiftCount(ShiftMember $m): int
    {
        return QueueEntry::query()->where('shift_member_id', $m->id)->count();
    }

    /** Gives the entry to the member's lowest free window and tells everyone. */
    public function assign(QueueEntry $e, ShiftMember $m, string $rule, string $ruleKey): void
    {
        $used = $m->openEntries()->whereNotNull('window_no')->pluck('window_no')->map(fn ($w) => (int) $w)->all();
        $window = 1;
        while (in_array($window, $used, true)) {
            $window++;
        }

        $e->forceFill([
            'status' => 'active', 'assigned_user_id' => $m->user_id, 'shift_member_id' => $m->id, 'shift_id' => $m->shift_id, 'window_no' => $window,
            'called_at' => now(), 'delivered_at' => now(), 'wait_seconds' => max(0, (int) $e->enqueued_at->diffInSeconds(now())), 'rule' => mb_substr($rule, 0, 120),
            'last_customer_message_at' => $e->last_customer_message_at ?? now(), 'reserved_user_id' => null,
        ])->save();

        $c = $e->conversation;
        $c->forceFill(['assignee_id' => $m->user_id, 'assigned_at' => now(), 'queue_entry_id' => $e->id])->save();
        $m->update(['status' => 'busy']);
        $user = $m->user;

        $this->logger->log(ActorType::System, null, ActivityLogger::QUEUE_ASSIGN, null, $c, ['ticket' => $e->ticket_no, 'user_id' => $m->user_id, 'window' => $window, 'rule' => $ruleKey]);
        SendQueueMessage::dispatch($e->id, 'queue_called', ['ticket' => $e->ticket_no, 'name' => $user->name, 'window' => $window]);
        $this->notifier->notify($user, 'queue.assigned', [
            'entry_id' => $e->id, 'conversation_id' => $c->id, 'ticket' => $e->ticket_no, 'window_no' => $window,
            'customer_name' => $c->customer?->name, 'platform' => $c->platform?->value, 'bot_summary' => $e->bot_summary,
        ]);

        // Real-time pushes only once the assignment is committed, so a client that refetches sees it.
        DB::afterCommit(function () use ($e, $m, $c) {
            SafeBroadcast::send(new QueueAssigned($m->user_id, $e));
            SafeBroadcast::send(new QueueEntryUpdated($e));
            SafeBroadcast::send(new QueueMemberUpdated($m->fresh()));
            SafeBroadcast::send(new ConversationUpdated($c->fresh()));
        });
    }

    private function line(QueueEntry $e, ShiftMember $m, string $rule): string
    {
        return '<b>#'.$e->ticket_no.'</b> <span class="hi">'.e($rule).'</span> ← <span class="ok">'.e($m->user->name).'</span>';
    }
}
