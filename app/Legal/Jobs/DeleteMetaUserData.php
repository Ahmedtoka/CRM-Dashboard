<?php

namespace App\Legal\Jobs;

use App\Channels\Adapters\MetaGraphClient;
use App\Inbox\UserNotifier;
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
 *
 * Meta sends an APP-scoped user id while identities hold PAGE-scoped ids (audit A8):
 * a direct match is tried first, then Meta's ids_for_pages mapping. Meta answering
 * with no page ids means the person never wrote to our pages (not_found); when Meta
 * cannot answer at all the request waits as needs_manual_review — never a false
 * "nothing found" — and active admins get a bell notification
 * (`php artisan legal:deletion-requests` lists what is open).
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

    public function handle(?PersonalDataEraser $eraser = null, ?MetaGraphClient $graph = null): void
    {
        $eraser ??= app(PersonalDataEraser::class);
        $graph ??= app(MetaGraphClient::class);
        $request = DataDeletionRequest::find($this->deletionRequestId);

        if ($request === null || $request->status !== DataDeletionRequest::STATUS_PENDING) {
            return;
        }

        $platformIds = [$request->external_user_id];
        $customerIds = $this->customersFor($request->platform, $platformIds);

        if ($customerIds === []) {
            $pageIds = $graph->idsForPages($request->external_user_id);

            if ($pageIds === null) {
                $request->forceFill(['status' => DataDeletionRequest::STATUS_NEEDS_REVIEW])->save();
                Log::warning('crm.data_deletion.needs_manual_review', ['request_id' => $request->id]);
                // The confirmation code only: the platform id stays off the bell.
                rescue(fn () => app(UserNotifier::class)->notifyAdmins('legal.deletion_review', [
                    'request_id' => $request->id,
                    'code' => $request->confirmation_code,
                ]), report: true);

                return;
            }

            $platformIds = array_values(array_unique([...$platformIds, ...$pageIds]));
            $customerIds = $this->customersFor($request->platform, $platformIds);
        }

        /** @var list<array{disk: string, path: string}> $files */
        $files = [];
        $counts = [];

        DB::transaction(function () use ($request, $customerIds, $platformIds, $eraser, &$files, &$counts) {
            $conversationIds = Conversation::whereIn('customer_id', $customerIds)->pluck('id')->all();

            ['counts' => $counts, 'files' => $files] = $eraser->eraseConversations($conversationIds, $customerIds);

            // Raw inbound payloads carry the sender's id and the message text.
            $counts['webhook_events'] = 0;

            foreach ($platformIds as $platformId) {
                $counts['webhook_events'] += WebhookEvent::where('provider', $request->platform)
                    ->where('payload', 'like', '%"'.$platformId.'"%')
                    ->delete();
            }
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

    /**
     * @param  list<string>  $platformIds
     * @return list<int>
     */
    private function customersFor(string $platform, array $platformIds): array
    {
        return CustomerIdentity::query()
            ->where('platform', $platform)
            ->whereIn('external_id', $platformIds)
            ->pluck('customer_id')
            ->unique()
            ->values()
            ->all();
    }
}
