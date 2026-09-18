<?php

namespace App\Legal;

use App\Models\ActivityLog;
use App\Models\BotLearningNote;
use App\Models\BotRun;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerIdentity;
use App\Models\CustomerMergeSuggestion;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Order;
use App\Models\SupportCase;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Storage;

/**
 * The one place that knows what hangs off a conversation / a customer, shared by
 * the Meta Data Deletion Callback (DeleteMetaUserData) and the retention prune
 * (crm:prune-retention). Every method only deletes rows: callers wrap it in a
 * transaction and call deleteFiles() with the returned files after the commit,
 * so a rolled-back transaction keeps both the rows and the files.
 */
final class PersonalDataEraser
{
    /**
     * Conversations with their messages, attachment rows, comments (plus the
     * page's own replies under them), bot runs, support cases, learning notes,
     * activity rows and staff notifications. With $customerIds, the rows those
     * customers own directly (comments, cases, activity about them) go too.
     * Notes, participants and tags cascade with the conversation.
     *
     * @param  list<int>  $conversationIds
     * @param  list<int>  $customerIds
     * @return array{counts: array<string, int>, files: list<array{disk: string, path: string}>}
     */
    public function eraseConversations(array $conversationIds, array $customerIds = []): array
    {
        $messageIds = $conversationIds === [] ? [] : Message::whereIn('conversation_id', $conversationIds)->pluck('id')->all();
        $attachments = $messageIds === [] ? collect() : MessageAttachment::whereIn('message_id', $messageIds)->get(['id', 'disk', 'path']);
        $files = $attachments
            ->filter(fn (MessageAttachment $a) => filled($a->disk) && filled($a->path))
            ->map(fn (MessageAttachment $a) => ['disk' => (string) $a->disk, 'path' => (string) $a->path])
            ->values()
            ->all();

        $commentIds = Comment::query()
            ->whereIn('customer_id', $customerIds)
            ->orWhereIn('conversation_id', $conversationIds)
            ->pluck('id')
            ->all();
        $comments = $this->eraseComments($commentIds);

        $counts = [
            'customers' => count($customerIds),
            'conversations' => count($conversationIds),
            'messages' => count($messageIds),
            'attachments' => MessageAttachment::whereIn('id', $attachments->pluck('id'))->delete(),
            'bot_runs' => BotRun::whereIn('conversation_id', $conversationIds)->delete() + $comments['bot_runs'],
            'comments' => $comments['comments'],
            'support_cases' => SupportCase::whereIn('conversation_id', $conversationIds)->orWhereIn('customer_id', $customerIds)->delete(),
            'learning_notes' => BotLearningNote::whereIn('conversation_id', $conversationIds)->delete(),
            'activity_logs' => ActivityLog::whereIn('conversation_id', $conversationIds)
                ->orWhere(fn ($q) => $q->where('subject_type', (new Customer)->getMorphClass())->whereIn('subject_id', $customerIds))
                ->delete(),
            'notifications' => $conversationIds === [] ? 0 : UserNotification::whereIn('data->conversation_id', $conversationIds)->delete(),
        ];

        Message::whereIn('id', $messageIds)->delete();
        Conversation::whereIn('id', $conversationIds)->delete();

        return ['counts' => $counts, 'files' => $files];
    }

    /**
     * Comments plus the page's own replies threaded under them (they quote the
     * person), and the bot runs that answered any of them.
     *
     * @param  list<int>  $commentIds
     * @return array{comments: int, bot_runs: int}
     */
    public function eraseComments(array $commentIds): array
    {
        if ($commentIds === []) {
            return ['comments' => 0, 'bot_runs' => 0];
        }

        $externalIds = Comment::whereIn('id', $commentIds)->pluck('external_id')->filter()->all();
        $ids = collect($commentIds)
            ->merge($externalIds === [] ? [] : Comment::whereIn('parent_external_id', $externalIds)->pluck('id'))
            ->unique()
            ->values()
            ->all();

        $botRuns = BotRun::whereIn('comment_id', $ids)->delete();

        return ['comments' => Comment::whereIn('id', $ids)->delete(), 'bot_runs' => $botRuns];
    }

    /**
     * The customer rows themselves with the CRM's copy of their orders (items and
     * shipments cascade), addresses, merge suggestions and platform identities.
     *
     * @param  list<int>  $customerIds
     * @return array{orders: int}
     */
    public function eraseCustomers(array $customerIds): array
    {
        if ($customerIds === []) {
            return ['orders' => 0];
        }

        $orders = Order::whereIn('customer_id', $customerIds)->delete();
        CustomerAddress::whereIn('customer_id', $customerIds)->delete();
        CustomerMergeSuggestion::whereIn('customer_id', $customerIds)->orWhereIn('candidate_id', $customerIds)->delete();
        CustomerIdentity::whereIn('customer_id', $customerIds)->delete();
        Customer::whereIn('id', $customerIds)->delete();

        return ['orders' => $orders];
    }

    /** @param  list<array{disk: string, path: string}>  $files */
    public function deleteFiles(array $files): void
    {
        foreach ($files as $file) {
            rescue(fn () => Storage::disk($file['disk'])->delete($file['path']), report: false);
        }
    }
}
