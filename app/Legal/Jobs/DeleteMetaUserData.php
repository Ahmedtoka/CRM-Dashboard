<?php

namespace App\Legal\Jobs;

use App\Legal\PersonalDataEraser;
use App\Models\Conversation;
use App\Models\CustomerIdentity;
use App\Models\DataDeletionRequest;
use App\Models\WebhookEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deletes everything the CRM holds about one Meta user (the `user_id` from a Data
 * Deletion Callback): every customer linked to that platform id, with their
 * conversations, messages, attachment files, comments, support cases, bot runs,
 * learning notes, activity rows, staff notifications, raw webhook payloads and the
 * CRM's copy of their orders / addresses. Other customers are never touched.
 * The row deletion itself is shared with crm:prune-retention (PersonalDataEraser).
 *
 * Logs counts only — never names, ids or message text.
 */
class DeleteMetaUserData implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public int $deletionRequestId) {}

    public function handle(?PersonalDataEraser $eraser = null): void
    {
        $eraser ??= app(PersonalDataEraser::class);
        $request = DataDeletionRequest::find($this->deletionRequestId);

        if ($request === null || $request->status !== DataDeletionRequest::STATUS_PENDING) {
            return;
        }

        $customerIds = CustomerIdentity::query()
            ->where('platform', $request->platform)
            ->where('external_id', $request->external_user_id)
            ->pluck('customer_id')
            ->unique()
            ->values()
            ->all();

        /** @var list<array{disk: string, path: string}> $files */
        $files = [];
        $counts = [];

        DB::transaction(function () use ($request, $customerIds, $eraser, &$files, &$counts) {
            $conversationIds = Conversation::whereIn('customer_id', $customerIds)->pluck('id')->all();

            ['counts' => $counts, 'files' => $files] = $eraser->eraseConversations($conversationIds, $customerIds);

            // Raw inbound payloads carry the sender's id and the message text.
            $counts['webhook_events'] = WebhookEvent::where('provider', $request->platform)
                ->where('payload', 'like', '%"'.$request->external_user_id.'"%')
                ->delete();
            $counts += $eraser->eraseCustomers($customerIds);

            $request->forceFill([
                'status' => $customerIds === [] ? DataDeletionRequest::STATUS_NOT_FOUND : DataDeletionRequest::STATUS_COMPLETED,
                'completed_at' => now(),
            ])->save();
        });

        // Files go only after the rows are gone for good (a rolled-back transaction keeps both).
        $eraser->deleteFiles($files);

        Log::info('crm.data_deletion.completed', [
            'request_id' => $request->id,
            'status' => $request->status,
            'files' => count($files),
        ] + $counts);
    }
}
