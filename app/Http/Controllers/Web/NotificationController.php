<?php

namespace App\Http\Controllers\Web;

use App\Enums\ConversationPriority;
use App\Http\Controllers\Controller;
use App\Inbox\ConversationQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The bell's data source (spec §5.4, Dashboard Experience Task 14): the
 * latest persisted notifications, an unread count, and the count of
 * visible conversations with unread messages for the tab title badge.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => $user->userNotifications()->latest('id')->limit(30)->get(['id', 'type', 'data', 'read_at', 'created_at']),
            'unread_notifications' => $user->userNotifications()->whereNull('read_at')->count(),
            // Spam/low-priority conversations never surface here, matching MetricsService's
            // "open conversations" convention (spec §11.1).
            'unread_conversations' => ConversationQuery::visibleTo($user)->where('unread_count', '>', 0)
                ->whereNotIn('priority', [ConversationPriority::Spam->value, ConversationPriority::Low->value])->count(),
        ]);
    }

    public function read(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['nullable', 'array'], 'ids.*' => ['integer']]);
        $request->user()->userNotifications()->whereNull('read_at')
            ->when(! empty($data['ids']), fn ($q) => $q->whereIn('id', $data['ids']))
            ->update(['read_at' => now()]);

        return response()->json(['unread_notifications' => $request->user()->userNotifications()->whereNull('read_at')->count()]);
    }
}
