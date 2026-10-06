<?php

namespace App\Inbox\Outcomes\Commands;

use App\Inbox\Outcomes\OutcomeRecorder;
use App\Models\Conversation;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Hourly (control room S3): ends the episodes nobody closed — a bot-only chat she left, or a chat
 * with the queue off that was never resolved — once nothing happened for `crm.outcomes.idle_hours`.
 * Candidates: not a test, no open queue entry, last message in [lookback, idle], and the customer
 * wrote after the last episode end. OutcomeRecorder::endIdle() decides the rest.
 */
class CloseIdleEpisodes extends Command
{
    protected $signature = 'outcomes:close-idle {--hours= : Idle hours before an episode ends (default crm.outcomes.idle_hours)}';

    protected $description = 'End conversation episodes that went idle without a close (no_answer / unknown outcome).';

    public function handle(OutcomeRecorder $recorder): int
    {
        $hours = (int) ($this->option('hours') ?: config('crm.outcomes.idle_hours', 24));
        $before = now()->subHours(max(1, $hours));
        $after = now()->subDays((int) config('crm.outcomes.lookback_days', 14));
        $ended = 0;

        Conversation::query()
            ->where('is_test', false)
            ->whereBetween('last_message_at', [$after, $before])
            ->whereNotNull('last_customer_message_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('queue_entries')
                ->whereColumn('queue_entries.conversation_id', 'conversations.id')
                ->whereIn('queue_entries.status', ['waiting', 'called', 'active']))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('conversation_outcomes as co')
                ->whereColumn('co.conversation_id', 'conversations.id')->whereNotNull('co.ended_at')
                ->whereColumn('co.ended_at', '>=', 'conversations.last_customer_message_at'))
            ->chunkById(200, function (Collection $rows) use ($recorder, &$ended) {
                foreach ($rows as $c) {
                    $ended += (int) rescue(fn () => $recorder->endIdle($c) !== null, false, report: true);
                }
            });

        $this->info("Ended {$ended} idle episodes.");

        return self::SUCCESS;
    }
}
