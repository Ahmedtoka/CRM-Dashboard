<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ConversationContextEndpoint;
use App\Http\Controllers\Concerns\ConversationEndpoints;
use App\Http\Controllers\Concerns\FinishEndpoints;
use App\Http\Controllers\Controller;
use App\Http\Resources\QueueEntryResource;
use App\Inbox\SoftLock;
use App\Inbox\WindowPolicy;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Queue\WindowLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    use ConversationContextEndpoint;
    use ConversationEndpoints {
        show as protected showThread;
    }
    use FinishEndpoints;

    /**
     * The thread, plus the two moderator clocks on `conversation.queue_entry` (mobile r2, additive):
     * `awaiting_reply_since`, `reply_overdue`, `handoff_left_seconds`, `silence_left_seconds` and
     * `awaiting` as QueueEntryResource computes them, so the app's thread header shows «مستنية ردك من»
     * / «مستنيين ردها · قفل تلقائي بعد» for a window that is not in her own /queue/me (a supervisor
     * reading a colleague's window). Nothing else in the payload changes.
     */
    public function show(Request $request, int|string $conversation, WindowPolicy $windows, SoftLock $lock): JsonResponse
    {
        $response = $this->showThread($request, $conversation, $windows, $lock);
        $data = $response->getData(true);

        $entryId = $data['conversation']['queue_entry']['id'] ?? null;
        if ($entryId !== null && ($entry = QueueEntry::query()->find($entryId)) !== null && $entry->isOpen()) {
            $settings = QueueSetting::current();
            $data['conversation']['queue_entry'] += [
                'awaiting_reply_since' => $entry->awaiting_reply_since?->toIso8601String(),
                'reply_overdue' => $entry->awaiting_reply_since !== null && $entry->apology_sent_at !== null,
                'handoff_left_seconds' => WindowLifecycle::handOffLeft($entry, $settings),
                'silence_left_seconds' => QueueEntryResource::silenceLeft($entry, $settings),
                'awaiting' => $entry->awaiting_reply_since !== null ? 'agent' : ($entry->last_agent_message_at !== null ? 'customer' : null),
            ];
            $response->setData($data);
        }

        return $response;
    }
}
