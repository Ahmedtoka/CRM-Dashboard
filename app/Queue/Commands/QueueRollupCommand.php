<?php

namespace App\Queue\Commands;

use App\Models\QueueEntry;
use App\Queue\QueueService;
use App\Queue\Reports\QueueReport;
use App\Queue\Scoring\ScoreListener;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The queue's nightly close of a business day (queue Part 2, rulings N11 / R25). Scheduled at
 * 00:20 Cairo for yesterday; safe to run again for any day (`php artisan queue:rollup 2026-10-05`).
 *
 *  1. Repair: every closed window of the day gets the base / speed / no-reply / rating lines it is
 *     owed. Listener failures are reported and swallowed by contract; the per-entry keys make this
 *     pay only what is missing, never twice.
 *  2. Archive: the day's QueueReport goes into `queue_days.summary` (the row the ticket counter
 *     uses; created when the day had none). `/reports/queue` still computes live.
 */
class QueueRollupCommand extends Command
{
    protected $signature = 'queue:rollup {date? : Cairo business day (Y-m-d), default yesterday}';

    protected $description = 'Handover queue: repair missing points of a business day and archive its report into queue_days';

    public function handle(ScoreListener $scores, QueueReport $report): int
    {
        $date = $this->argument('date')
            ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->argument('date'), QueueService::TZ)->toDateString()
            : CarbonImmutable::now(QueueService::TZ)->subDay()->toDateString();

        $repaired = 0;
        QueueEntry::query()->where('status', 'closed')->where('is_test', false)->whereNotNull('assigned_user_id')
            ->whereDate('business_date', $date)->orderBy('id')
            ->chunkById(500, function ($entries) use ($scores, &$repaired) {
                foreach ($entries as $e) {
                    rescue(function () use ($scores, $e) {
                        $scores->forEntry($e);
                        if ($e->close_reason === 'no_reply') {
                            $scores->noReply($e);
                        }
                        $scores->rated($e);
                    }, null, report: true);
                    $repaired++;
                }
            });

        $summary = $report->build($date, $date) + ['generated_at' => now()->toIso8601String()];
        $stamp = now();
        // Plain 'Y-m-d' through the query builder, like QueueService::nextTicket(): one row per day on every driver.
        DB::table('queue_days')->upsert(
            [['date' => $date, 'next_ticket' => 1, 'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE), 'created_at' => $stamp, 'updated_at' => $stamp]],
            ['date'],
            ['summary', 'updated_at'],
        );

        $this->info("Queue day {$date}: {$repaired} windows checked, ".count($summary['rows']).' moderators archived.');

        return self::SUCCESS;
    }
}
