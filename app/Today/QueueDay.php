<?php

namespace App\Today;

use App\Models\QueueEntry;
use App\Models\QueueSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * The queue's numbers of one business date: what BoardState::kpis() shows live for today, for any day. «امبارح»
 * on /today is the end-of-day queue report the routing spec promised (G12).
 */
final class QueueDay
{
    /** @return array{issued:int, closed:array<string,int>, closed_manual:int, closed_total:int, abandoned:int, sla_replied:int, sla_pct:?int, sla_target_pct:int, avg_wait_seconds:?int} */
    public function for(string $businessDate): array
    {
        $stored = (new QueueEntry)->fromDateTime(Carbon::parse($businessDate));

        $day = QueueEntry::query()->toBase()->where('business_date', $stored)
            ->selectRaw('status, close_reason, COUNT(*) as n, SUM(CASE WHEN ticket_no < 100000 THEN 1 ELSE 0 END) as tickets, SUM(CASE WHEN first_reply_at IS NOT NULL THEN 1 ELSE 0 END) as replied, SUM(CASE WHEN sla_met = 1 THEN 1 ELSE 0 END) as met')
            ->groupBy('status', 'close_reason')->get();

        $closed = [];
        foreach (QueueEntry::CLOSE_REASONS as $reason) {
            $closed[$reason] = (int) $day->where('close_reason', $reason)->sum('n');
        }

        $replied = (int) $day->sum('replied');
        $waits = QueueEntry::query()->toBase()->where('business_date', $stored)->whereNotNull('called_at')->whereNotNull('enqueued_at')
            ->get(['enqueued_at', 'called_at'])
            ->map(fn (object $r) => max(0, (int) CarbonImmutable::parse((string) $r->enqueued_at, (string) config('app.timezone'))
                ->diffInSeconds(CarbonImmutable::parse((string) $r->called_at, (string) config('app.timezone')))));

        return [
            'issued' => (int) $day->sum('tickets'),
            'closed' => $closed,
            'closed_manual' => $closed['inquiry'] + $closed['problem'] + $closed['case'],
            'closed_total' => array_sum($closed),
            'abandoned' => (int) $day->where('status', 'abandoned')->sum('n'),
            'sla_replied' => $replied,
            'sla_pct' => $replied > 0 ? (int) round(100 * (int) $day->sum('met') / $replied) : null,
            'sla_target_pct' => (int) QueueSetting::current()->sla_target_pct,
            'avg_wait_seconds' => $waits->isEmpty() ? null : (int) round($waits->avg()),
        ];
    }
}
