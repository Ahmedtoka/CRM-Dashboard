<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\ConversationPriority;
use App\Inbox\ConversationQuery;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The bell's data source (spec §5.4, Dashboard Experience Task 14), shared by the web
 * (`Web\NotificationController`) and the mobile app (`Api\V1\NotificationController`): the
 * latest persisted notifications, an unread count, and the count of visible conversations
 * with unread messages for the tab title badge.
 */
trait NotificationEndpoints
{
    public function read(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['nullable', 'array'], 'ids.*' => ['integer']]);
        $request->user()->userNotifications()->whereNull('read_at')
            ->when(! empty($data['ids']), fn ($q) => $q->whereIn('id', $data['ids']))
            ->update(['read_at' => now()]);

        return response()->json(['unread_notifications' => $request->user()->userNotifications()->whereNull('read_at')->count()]);
    }

    /**
     * The latest 30 of her notifications (older than `$beforeId` when given) and the two badges.
     *
     * @return array<string, mixed>
     */
    protected function notificationsPayload(User $user, ?int $beforeId = null): array
    {
        return [
            'data' => $user->userNotifications()->when($beforeId !== null, fn ($q) => $q->where('id', '<', $beforeId))
                ->latest('id')->limit(30)->get(['id', 'type', 'data', 'read_at', 'created_at']),
            'unread_notifications' => $user->userNotifications()->whereNull('read_at')->count(),
            // Spam/low-priority conversations never surface here, matching MetricsService's
            // "open conversations" convention (spec §11.1).
            'unread_conversations' => ConversationQuery::visibleTo($user)->where('unread_count', '>', 0)
                ->whereNotIn('priority', [ConversationPriority::Spam->value, ConversationPriority::Low->value])->count(),
        ];
    }
}
