<?php

namespace App\Shipping;

use App\Inbox\UserNotifier;
use App\Models\Conversation;
use App\Models\Fulfillment;
use App\Models\FulfillmentEvent;
use App\Models\Order;
use App\Models\User;
use App\Queue\QueueService;
use Illuminate\Support\Facades\Cache;

/**
 * «المندوب معرفش يسلّم» (round 2026-10-24): when Shopify reports a failed delivery attempt, every active supervisor
 * and admin gets an `order.delivery_failed` bell, and so does the moderator currently serving the order's
 * conversation (if any). Once per fulfillment event.
 */
final class DeliveryFailureAlert
{
    public const TYPE = 'order.delivery_failed';

    public function __construct(private readonly UserNotifier $notifier) {}

    /** An import or backfill of old fulfillments must not ring the bell for deliveries that failed weeks ago. */
    public const FRESH_HOURS = 36;

    public function raise(Fulfillment $fulfillment, FulfillmentEvent $event): void
    {
        if ($event->happened_at === null || $event->happened_at->lt(now()->subHours(self::FRESH_HOURS))) {
            return;
        }

        if (! Cache::add('delivery-failed-alert:'.$event->id, 1, now()->addDay())) {
            return;
        }

        $order = Order::withLoadTest()->with('customer:id,name')->find($fulfillment->order_id);

        if ($order === null || $order->is_load_test) {
            return;
        }

        $data = [
            'order_id' => $order->id,
            'order_number' => $order->shopify_order_name ?? $order->order_number,
            'customer_name' => $order->customer?->name ?? $order->shipping_name,
            'conversation_id' => $order->conversation_id,
            'tracking_company' => $fulfillment->tracking_company,
            'tracking_number' => $fulfillment->tracking_number,
            'status' => $event->status,
            'message' => $event->message,
            'happened_at' => $event->happened_at?->toIso8601String(),
        ];

        $recipients = User::query()->where('is_active', true)->get()->filter(fn (User $u) => $u->isSupervisorOrAbove());

        $conversation = $order->conversation_id !== null ? Conversation::query()->find($order->conversation_id) : null;
        $assignee = $conversation !== null ? app(QueueService::class)->activeEntry($conversation)?->assigned_user_id : null;

        if ($assignee !== null && ! $recipients->contains('id', (int) $assignee)) {
            $moderator = User::query()->where('is_active', true)->find((int) $assignee);
            if ($moderator !== null) {
                $recipients->push($moderator);
            }
        }

        $recipients->each(fn (User $u) => $this->notifier->notify($u, self::TYPE, $data));
    }
}
