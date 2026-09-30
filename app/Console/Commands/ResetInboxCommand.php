<?php

namespace App\Console\Commands;

use App\Models\ChannelAccount;
use App\Models\Conversation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A clean start for a live test (owner, 2026-09-30): every conversation with its messages and
 * everything that belongs to it, plus the queue's day (tickets, shifts, desks, attendance).
 *
 * Deleting a conversation cascades to its messages (and their attachment rows), notes, tags,
 * participants, queue entries and support cases; orders, comments, bot runs and activity logs
 * keep their rows with the link emptied. Customers, users, channels, settings, bot knowledge
 * and Shopify data are not touched. Media files stay on disk.
 *
 * Without --force it only counts. Nothing can bring the data back, so take a backup first.
 */
class ResetInboxCommand extends Command
{
    protected $signature = 'crm:reset-inbox
        {--account=* : Only the conversations of these channel account ids (the queue tables are then left alone)}
        {--force : Really delete (without it the command only counts)}';

    protected $description = 'Delete all conversations and messages (and the queue day) for a clean live test — counts only unless --force';

    /** Emptied on a full reset, children first: the queue's day and the bell (its items point at deleted conversations). */
    private const QUEUE_TABLES = ['queue_decisions', 'queue_attendance_events', 'shift_members', 'shifts', 'queue_days', 'user_notifications'];

    public function handle(): int
    {
        $accounts = array_values(array_filter(array_map('intval', (array) $this->option('account'))));
        $conversations = Conversation::query()->when($accounts !== [], fn ($q) => $q->whereIn('channel_account_id', $accounts));

        $perAccount = (clone $conversations)->selectRaw('channel_account_id, count(*) as n')->groupBy('channel_account_id')->pluck('n', 'channel_account_id');
        $names = ChannelAccount::query()->whereIn('id', $perAccount->keys())->get()->keyBy('id');

        $rows = $perAccount->map(fn ($n, $id) => [
            $id,
            $names[$id]?->name ?? '—',
            $names[$id]?->platform instanceof \BackedEnum ? $names[$id]->platform->value : ($names[$id]?->platform ?? '—'),
            $n,
            DB::table('messages')->whereIn('conversation_id', (clone $conversations)->where('channel_account_id', $id)->select('id'))->count(),
        ])->values()->all();

        $this->table(['Account id', 'Name', 'Platform', 'Conversations', 'Messages'], $rows);

        $queue = $accounts === [] ? collect(self::QUEUE_TABLES)->filter(fn ($t) => Schema::hasTable($t))->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]) : collect();
        if ($queue->isNotEmpty()) {
            $this->table(['Queue table', 'Rows'], $queue->map(fn ($n, $t) => [$t, $n])->values()->all());
        }

        if (! $this->option('force')) {
            $this->warn('Nothing was deleted. Run again with --force to delete the rows above (take a backup first).');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($conversations, $accounts) {
            // The conversation points at its queue entry and the entry at the conversation: clear the first link.
            (clone $conversations)->update(['queue_entry_id' => null]);
            (clone $conversations)->select('id')->chunkById(500, function ($chunk) {
                Conversation::query()->whereIn('id', $chunk->pluck('id'))->delete();
            });

            if ($accounts === []) {
                foreach (self::QUEUE_TABLES as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->delete();
                    }
                }
            }
        });

        $this->info('Done. Moderators press «بدأت شغل» again; tickets start again at #1.');

        return self::SUCCESS;
    }
}
