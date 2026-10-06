<?php

namespace App\Inbox\Outcomes\Commands;

use App\Inbox\Outcomes\OutcomeRecorder;
use App\Models\Conversation;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Hourly (control room S3): ends the episodes nobody closed — a bot-only chat she left, or a chat
 * with the queue off that was never resolved — once nothing happened for `crm.outcomes.idle_hours`.
 * Candidates: not a test, no open queue entry, the customer wrote after tracking started and after
 * the last episode end, and the chat went quiet within the last `crm.outcomes.recheck_hours` before
 * the idle mark (so a chat with nothing real to end, e.g. only a rating answer, is looked at by a few
 * runs, not every hour for weeks). OutcomeRecorder::endIdle() decides the rest.
 */
class CloseIdleEpisodes extends Command
{
    protected $signature = 'outcomes:close-idle {--hours= : Idle hours before an episode ends (default crm.outcomes.idle_hours)}';

    protected $description = 'End conversation episodes that went idle without a close (no_answer / unknown outcome).';

    public function handle(OutcomeRecorder $recorder): int
    {
        $ended = 0;

        $this->candidates((int) ($this->option('hours') ?: config('crm.outcomes.idle_hours', 24)), $recorder)
            ->chunkById(200, function (Collection $rows) use ($recorder, &$ended) {
                foreach ($rows as $c) {
                    $ended += (int) rescue(fn () => $recorder->endIdle($c) !== null, false, report: true);
                }
            });

        $this->info("Ended {$ended} idle episodes.");

        return self::SUCCESS;
    }

    /** @return Builder<Conversation> the conversations this run looks at */
    public function candidates(int $hours, OutcomeRecorder $recorder): Builder
    {
        $before = now()->subHours(max(1, $hours));
        $after = $before->copy()->subHours(max(1, (int) config('crm.outcomes.recheck_hours', 12)));

        return Conversation::query()
            ->where('is_test', false)
            ->where('last_message_at', '>', $after)
            ->where('last_message_at', '<=', $before)
            // No history backfill: only chats where she wrote after tracking started.
            ->where('last_customer_message_at', '>=', $recorder->trackingFrom())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('queue_entries')
                ->whereColumn('queue_entries.conversation_id', 'conversations.id')
                ->whereIn('queue_entries.status', ['waiting', 'called', 'active']))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('conversation_outcomes as co')
                ->whereColumn('co.conversation_id', 'conversations.id')->whereNotNull('co.ended_at')
                ->whereColumn('co.ended_at', '>=', 'conversations.last_customer_message_at'));
    }
}
