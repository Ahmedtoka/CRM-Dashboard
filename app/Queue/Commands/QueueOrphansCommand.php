<?php

namespace App\Queue\Commands;

use App\Bot\BotEngine;
use App\Enums\Handler;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Close cycle Q13 (close-cycle-FINAL §2): chats left «with a person» nobody holds — `handler=human`,
 * no waiting / open queue entry, the latest entry ended more than `return_priority_minutes` ago and
 * no staff reply since then — go back to the bot. A dry run by default (a list for the leader);
 * `--apply` resets them, each under its conversation lock, re-checked there.
 */
class QueueOrphansCommand extends Command
{
    protected $signature = 'queue:orphans {--apply : reset the listed chats to the bot} {--limit=500}';

    protected $description = 'List (or with --apply hand back to the bot) human-handled chats nobody holds after their queue entry ended';

    public function handle(BotEngine $bot): int
    {
        $s = QueueSetting::current();
        $before = now()->subMinutes((int) $s->return_priority_minutes);
        $apply = (bool) $this->option('apply');
        $rows = [];

        Conversation::query()->where('handler', Handler::Human->value)
            ->whereNotExists(fn ($q) => $q->from('queue_entries')->whereColumn('queue_entries.conversation_id', 'conversations.id')->whereIn('status', ['waiting', 'called', 'active']))
            ->whereExists(fn ($q) => $q->from('queue_entries')->whereColumn('queue_entries.conversation_id', 'conversations.id'))
            ->orderBy('id')->limit((int) $this->option('limit'))->get()
            ->each(function (Conversation $c) use ($before, $apply, $bot, &$rows) {
                if (! self::orphan($c, $before)) {
                    return;
                }

                $rows[] = [$c->id, $c->customer?->name, optional(QueueEntry::query()->where('conversation_id', $c->id)->latest('id')->value('closed_at'))->toString() ?? ''];

                if ($apply) {
                    DB::transaction(function () use ($c, $before, $bot) {
                        $row = Conversation::query()->lockForUpdate()->find($c->id);

                        if ($row !== null && $row->handler === Handler::Human && self::orphan($row, $before)) {
                            $row->return_priority_until = null;
                            $bot->resetToBot($row);
                        }
                    }, attempts: 3);
                }
            });

        $this->table(['conversation', 'customer', 'last entry closed'], $rows);
        $this->info(($apply ? 'Handed back to the bot: ' : 'Would hand back (dry run, add --apply): ').count($rows));

        return self::SUCCESS;
    }

    private static function orphan(Conversation $c, $before): bool
    {
        $last = QueueEntry::query()->where('conversation_id', $c->id)->latest('id')->first(['id', 'status', 'closed_at']);

        if ($last === null || ! in_array($last->status, QueueEntry::TERMINAL_STATUSES, true) || $last->closed_at === null || $last->closed_at->greaterThan($before)) {
            return false;
        }

        if ($c->return_priority_until !== null && $c->return_priority_until->isFuture()) {
            return false;
        }

        return ! Message::query()->where('conversation_id', $c->id)->where('direction', MessageDirection::Out->value)
            ->where('sender_type', SenderType::User->value)->where('created_at', '>', $before)->exists();
    }
}
