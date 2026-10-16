<?php

namespace App\Queue\Cases;

use App\Inbox\UserNotifier;
use App\Models\SupportCase;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * The SLA of a case opened from a queue window (queue Part 2, ruling N6: calendar hours,
 * `queue_settings.case_sla_hours`, stamped at the case close as `sla_due_at`).
 */
class CaseSla
{
    /**
     * open (time left) · overdue · in_time / late (resolved) · null (no SLA, or closed without a solution).
     *
     * @return array{state: ?string, due_at: ?string, resolved_at: ?string}
     */
    public static function of(SupportCase $case, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $due = $case->sla_due_at;
        $state = match (true) {
            $due === null => null,
            $case->status === 'resolved' && $case->resolved_at !== null => $case->resolved_at->lte($due) ? 'in_time' : 'late',
            in_array($case->status, SupportCase::OPEN_STATUSES, true) => $due->lt($now) ? 'overdue' : 'open',
            default => null,
        };

        return ['state' => $state, 'due_at' => $due?->toIso8601String(), 'resolved_at' => $case->resolved_at?->toIso8601String()];
    }

    /**
     * Once per case: a still-open queue case whose due time passed is stamped `sla_breached_at` and
     * its opener and assignee are told (`cases.sla_overdue`). Called by the queue tick.
     */
    public function sweep(): int
    {
        $n = 0;
        $due = SupportCase::query()->whereIn('status', SupportCase::OPEN_STATUSES)->whereNotNull('opened_by_id')
            ->whereNotNull('sla_due_at')->where('sla_due_at', '<', now())->whereNull('sla_breached_at')->orderBy('id')->limit(200)->get();

        foreach ($due as $case) {
            // Conditional stamp: two ticks at once never tell twice.
            if (SupportCase::query()->whereKey($case->id)->whereNull('sla_breached_at')->update(['sla_breached_at' => now()]) !== 1) {
                continue;
            }

            $n++;
            $data = ['case_id' => $case->id, 'type' => $case->typeLabel(), 'conversation_id' => $case->conversation_id];
            $ids = array_values(array_filter(array_unique([(int) $case->opened_by_id, (int) $case->assigned_to_id])));
            User::query()->where('is_active', true)->whereIn('id', $ids)->orderBy('id')->get()
                ->each(fn (User $u) => app(UserNotifier::class)->notify($u, 'cases.sla_overdue', $data));
        }

        return $n;
    }
}
