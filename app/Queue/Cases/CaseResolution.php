<?php

namespace App\Queue\Cases;

use App\Models\ScoreEvent;
use App\Models\SupportCase;
use App\Models\User;
use App\Queue\Jobs\SendQueueMessage;
use App\Queue\QueueService;
use App\Queue\Scoring\ScoreKeeper;
use Illuminate\Support\Facades\DB;

/**
 * A case's status change from the Cases page (queue Part 2, ruling N6). `resolved` = staff solved
 * it: `resolved_at/by` are stamped, the moderator who opened it from her window earns
 * `case_resolved_in_time` (resolved by `sla_due_at`) or `case_resolved_late` on the Cairo day of
 * the resolution, and the customer gets `queue_case_resolved` (only while her reply window is
 * open; SendQueueMessage logs a closed one). Leaving `resolved` takes those points back; resolving
 * again pays again with the timing of then. `closed` (no solution) is never paid. Flow-collected
 * cases (no opener) and test chats pay nobody.
 */
class CaseResolution
{
    public function __construct(private readonly ScoreKeeper $keeper) {}

    public function setStatus(SupportCase $case, string $status, User $by): SupportCase
    {
        return DB::transaction(function () use ($case, $status, $by) {
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
                $this->pay($locked);
            }

            return $locked;
        }, attempts: 3);
    }

    private function pay(SupportCase $case): void
    {
        $entry = $case->queue_entry_id !== null ? $case->queueEntry : null;

        if ($entry === null || $entry->is_test || $case->opened_by_id === null) {
            return;
        }

        SendQueueMessage::dispatch($entry->id, 'queue_case_resolved', ['case_id' => $case->id]);

        $id = $case->id;
        $opener = (int) $case->opened_by_id;
        $inTime = $case->sla_due_at === null || $case->resolved_at->lte($case->sla_due_at);
        $refs = ['support_case_id' => $id, 'conversation_id' => $case->conversation_id, 'queue_entry_id' => $entry->id];

        DB::afterCommit(fn () => rescue(function () use ($id, $opener, $inTime, $refs) {
            $points = app(QueueService::class)->settings()->point($inTime ? 'case_resolved_in_time' : 'case_resolved_late');

            if ($points === 0) {
                return;
            }

            // One key per resolution: a reopened case resolved again is a new award.
            $n = ScoreEvent::query()->where('support_case_id', $id)->where('kind', 'case')->whereNull('reverses_id')->count() + 1;
            $this->keeper->award($opener, 'case', $points, $inTime ? 'case_in_time' : 'case_late', ['case' => $id], "case:{$id}:{$n}", $refs);
        }, null, true));
    }
}
