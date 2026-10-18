<?php

namespace App\Http\Controllers\Concerns;

use App\Inbox\ConversationAdContext;
use App\Inbox\HandoverDigest;
use App\Inbox\Outcomes\OutcomeRecorder;
use App\Models\Conversation;
use App\Models\Order;
use App\Queue\QueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Control room S3: the chat's outcome state, the bot's digest and the ad block (lazy, off the
 * 15-query thread). Shared by the web inbox (`Web\InboxController`) and the mobile app
 * (`Api\V1\ConversationController`); same JSON on both.
 */
trait ConversationContextEndpoint
{
    public function context(Request $request, Conversation $conversation, OutcomeRecorder $outcomes, HandoverDigest $digest, ConversationAdContext $ads): JsonResponse
    {
        Gate::authorize('view', $conversation);

        $entry = app(QueueService::class)->activeEntry($conversation);
        $episode = $outcomes->episode($conversation);
        $row = $outcomes->currentRow($conversation);
        // Close cycle (the «خلصت» sheet): why the outcome is preselected — the order placed in this episode.
        $order = $row?->order_id !== null ? Order::withLoadTest()->find($row->order_id, ['id', 'order_number', 'created_at']) : null;

        return response()->json(['data' => [
            'outcome' => [
                'current' => $row?->outcome,
                'source' => $row?->source,
                'auto' => $outcomes->autoOutcome($conversation, $entry, $episode['since'], sinceKnown: true)?->value,
                'auto_order' => $order ? ['id' => $order->id, 'number' => $order->order_number, 'created_at' => $order->created_at?->toIso8601String()] : null,
            ],
            'handover' => $digest->for($conversation, $entry, $episode['since']),
            'ad' => $ads->for($conversation, $request->user()),
        ]]);
    }
}
