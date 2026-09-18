<?php

namespace App\Legal\Commands;

use App\Legal\PersonalDataEraser;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Order;
use App\Models\SupportCase;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The retention promise of the privacy policy (lang/{ar,en}/legal.php):
 * conversations, comments and support cases are kept `crm.legal.retention_months`
 * after the last message, then deleted. Scheduled daily 04:00 Africa/Cairo
 * (LegalServiceProvider).
 *
 * - Conversations whose last message is older than the window go with their
 *   messages, attachment files, comments, bot runs, cases, learning notes,
 *   activity rows and notifications (PersonalDataEraser, the same code as the
 *   Meta Data Deletion Callback). One transaction per conversation, re-checked
 *   under a row lock so a message that just arrived keeps the conversation.
 * - Page comments that never became a conversation, older than the window.
 * - Customers of those conversations / comments left with no conversation,
 *   order, comment or case. Customers with orders (business records) and every
 *   other customer are never touched.
 *
 * Logs counts only, never names, ids or message text.
 */
class PruneRetentionCommand extends Command
{
    protected $signature = 'crm:prune-retention
        {--dry-run : Only count what would be deleted}
        {--chunk=100 : Rows read per batch}';

    protected $description = 'Delete conversations, comments and customers older than the privacy-policy retention window';

    public function handle(PersonalDataEraser $eraser): int
    {
        $months = max(1, (int) config('crm.legal.retention_months', 24));
        $cutoff = now()->subMonths($months);
        $chunk = max(1, (int) $this->option('chunk'));
        $dry = (bool) $this->option('dry-run');

        $counts = $dry ? $this->countOnly($cutoff) : $this->prune($eraser, $cutoff, $chunk);

        Log::info('crm.retention.pruned', ['months' => $months, 'dry_run' => $dry] + $counts);

        $this->info(($dry ? '[dry run] would delete: ' : 'Deleted: ').collect($counts)->map(fn ($n, $k) => "{$k}={$n}")->implode(', '));

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function prune(PersonalDataEraser $eraser, CarbonInterface $cutoff, int $chunk): array
    {
        $totals = array_fill_keys(['conversations', 'messages', 'attachments', 'comments', 'bot_runs', 'support_cases', 'learning_notes', 'activity_logs', 'notifications', 'customers', 'files'], 0);
        $add = function (array $counts) use (&$totals): void {
            foreach ($counts as $key => $n) {
                if (array_key_exists($key, $totals)) {
                    $totals[$key] += (int) $n;
                }
            }
        };

        /** @var array<int, true> $customers */
        $customers = [];
        $lastId = 0;

        do {
            $rows = $this->staleConversations($cutoff)->where('id', '>', $lastId)->orderBy('id')->limit($chunk)->get(['id', 'customer_id']);

            foreach ($rows as $row) {
                $lastId = (int) $row->id;
                $files = [];

                $deleted = DB::transaction(function () use ($row, $cutoff, $eraser, $add, &$files): bool {
                    // Re-checked under a lock: a message that arrived since the read keeps it.
                    if (! $this->staleConversations($cutoff)->whereKey($row->id)->lockForUpdate()->exists()) {
                        return false;
                    }

                    ['counts' => $counts, 'files' => $files] = $eraser->eraseConversations([(int) $row->id]);
                    unset($counts['customers']);
                    $add($counts);

                    return true;
                });

                if ($deleted) {
                    // Files go only after the rows are gone for good.
                    $eraser->deleteFiles($files);
                    $totals['files'] += count($files);
                    $customers[(int) $row->customer_id] = true;
                }
            }
        } while ($rows->count() === $chunk);

        $lastId = 0;

        do {
            $rows = $this->staleComments($cutoff)->where('id', '>', $lastId)->orderBy('id')->limit($chunk)->get(['id', 'customer_id']);

            if ($rows->isNotEmpty()) {
                $lastId = (int) $rows->last()->id;
                $add(DB::transaction(fn () => $eraser->eraseComments($rows->pluck('id')->map(fn ($id) => (int) $id)->all())));

                foreach ($rows->pluck('customer_id')->filter() as $id) {
                    $customers[(int) $id] = true;
                }
            }
        } while ($rows->count() === $chunk);

        foreach (array_keys($customers) as $id) {
            $totals['customers'] += DB::transaction(function () use ($id, $eraser, $add): int {
                if (! Customer::whereKey($id)->lockForUpdate()->exists() || ! $this->leftEmpty($id)) {
                    return 0;
                }

                ['counts' => $counts] = $eraser->eraseConversations([], [$id]);
                $add(['activity_logs' => $counts['activity_logs']]);
                $eraser->eraseCustomers([$id]);

                return 1;
            });
        }

        return $totals;
    }

    /** @return array<string, int> */
    private function countOnly(CarbonInterface $cutoff): array
    {
        $staleIds = fn () => $this->staleConversations($cutoff)->select('id');

        return [
            'conversations' => $this->staleConversations($cutoff)->count(),
            'messages' => Message::whereIn('conversation_id', $staleIds())->count(),
            'comments' => Comment::whereIn('conversation_id', $staleIds())->count() + $this->staleComments($cutoff)->count(),
            // Customers whose every conversation is stale, with no order and no recent comment.
            'customers' => Customer::query()
                ->whereIn('id', $this->staleConversations($cutoff)->select('customer_id'))
                ->whereNotIn('id', Conversation::query()->whereNotIn('id', $staleIds())->select('customer_id'))
                ->whereNotIn('id', Order::query()->select('customer_id'))
                ->whereNotIn('id', Comment::query()->whereNotNull('customer_id')->where('created_at', '>=', $cutoff)->select('customer_id'))
                ->count(),
        ];
    }

    /** Conversations whose last activity (last message, else creation) is before the cutoff. */
    private function staleConversations(CarbonInterface $cutoff): Builder
    {
        return Conversation::query()
            ->where(fn (Builder $q) => $q->where('last_message_at', '<', $cutoff)
                ->orWhere(fn (Builder $q) => $q->whereNull('last_message_at')->where('created_at', '<', $cutoff)))
            ->whereDoesntHave('messages', fn (Builder $q) => $q->where('created_at', '>=', $cutoff));
    }

    /** Page comments that never became a conversation, older than the cutoff. */
    private function staleComments(CarbonInterface $cutoff): Builder
    {
        return Comment::query()->whereNull('conversation_id')->where('created_at', '<', $cutoff);
    }

    private function leftEmpty(int $customerId): bool
    {
        return ! Conversation::where('customer_id', $customerId)->exists()
            && ! Order::where('customer_id', $customerId)->exists()
            && ! Comment::where('customer_id', $customerId)->exists()
            && ! SupportCase::where('customer_id', $customerId)->exists();
    }
}
