<?php

namespace App\Queue\Cases;

use App\Models\ScoreEvent;
use App\Models\SupportCase;
use App\Models\User;
use App\Queue\Jobs\SendCaseResolved;
use App\Queue\QueueService;
use App\Queue\Scoring\ScoreKeeper;
use Illuminate\Support\Facades\DB;

/**
 * A case's status change from the Cases page (queue Part 2, ruling N6). `resolved` = staff solved
 * it: `resolved_at/by` are stamped and the moderator whose window opened it (the window's
 * assignee, also when a supervisor pressed «خلصت → طلب» for her) earns `case_resolved_in_time`
 * (resolved by `sla_due_at`) or `case_resolved_late` on the Cairo day of the resolution — unless
 * she resolved it herself (then nobody is paid: a case must be solved by somebody else's check).
 * The customer gets `queue_case_resolved` once per case, at the first resolution (only while her
 * reply window is open; SendQueueMessage logs a closed one). Leaving `resolved` takes the points
 * back; resolving again pays again with the timing of then. `closed` (no solution) is never paid.
 * Flow-collected cases (no queue window) and test chats pay nobody.
 */
class CaseResolution
{
    public function __construct(private readonly ScoreKeeper $keeper) {}

    /** `$notify` false: the customer is not told here (close cycle: «خلصت» with «اتحل», the closing message is the last word). */
    public function setStatus(SupportCase $case, string $status, User $by, bool $notify = true): SupportCase
    {
        return DB::transaction(function () use ($case, $status, $by, $notify) {
            $locked = SupportCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            $was = $locked->status;

            if ($was === $status) {
                return $locked;
            }

            $locked->status = $status;
            $locked->forceFill($status === 'closed'
                ? ['closed_at' => now(), 'closed_by_id' => $by->id]
                : ['closed_at' => null, 'closed_by_id' => null]);
            $locked->forceFill($status === 'resolved'
                ? ['resolved_at' => now(), 'resolved_by_id' => $by->id]
                : ['resolved_at' => null, 'resolved_by_id' => null]);
            $locked->save();

            if ($was === 'resolved') {
                $id = $locked->id;
                DB::afterCommit(fn () => rescue(fn () => $this->keeper->reverseWhere('support_case_id', $id, ['case'], 'case_reopened', ['case' => $id]), null, true));
            }

            if ($status === 'resolved') {
                $this->resolved($locked, $by, $notify);
            }

            return $locked;
        }, attempts: 3);
    }

    /** The moderator a queue case pays: its window's assignee, else whoever opened it. */
    public static function payeeOf(SupportCase $case): ?int
    {
        $entry = $case->queue_entry_id !== null ? $case->queueEntry : null;
        $id = $entry?->assigned_user_id ?? $case->opened_by_id;

        return $id !== null ? (int) $id : null;
    }

    private function resolved(SupportCase $case, User $by, bool $notify = true): void
    {
        $entry = $case->queue_entry_id !== null ? $case->queueEntry : null;

        // Close cycle §6.3 (Q3, Q11, D-04, RC-14): once per case, for a bot case too; the job waits until
        // she is the bot's or closed, and asks the window's rating with it. `$notify` false: «خلصت» with «اتحل».
        if (! $notify) {
            $case->forceFill(['resolved_notified_at' => $case->resolved_notified_at ?? now()])->save();
        } elseif ($case->resolved_notified_at === null && ! $entry?->is_test) {
            $case->forceFill(['resolved_notified_at' => now()])->save();
            SendCaseResolved::dispatch($case->id);
        }

        if ($entry === null || $entry->is_test) {
            return;
        }

        $payee = self::payeeOf($case);

        if ($payee === null || $payee === (int) $by->id) {
            return;
        }

        $id = $case->id;
        $inTime = $case->sla_due_at === null || $case->resolved_at->lte($case->sla_due_at);
        $refs = ['support_case_id' => $id, 'conversation_id' => $case->conversation_id, 'queue_entry_id' => $entry->id];

        DB::afterCommit(fn () => rescue(function () use ($id, $payee, $inTime, $refs) {
            $points = app(QueueService::class)->settings()->point($inTime ? 'case_resolved_in_time' : 'case_resolved_late');

            if ($points === 0) {
                return;
            }

            // One key per resolution: a reopened case resolved again is a new award.
            $n = ScoreEvent::query()->where('support_case_id', $id)->where('kind', 'case')->whereNull('reverses_id')->count() + 1;
            $this->keeper->award($payee, 'case', $points, $inTime ? 'case_in_time' : 'case_late', ['case' => $id], "case:{$id}:{$n}", $refs);
        }, null, true));
    }
}
