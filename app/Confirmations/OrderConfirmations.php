<?php

namespace App\Confirmations;

use App\Confirmations\Jobs\SendOrderConfirmation;
use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\OrderConfirmation;
use App\Support\DataFloor;
use App\TestLinks\TestScope;

/**
 * Decides whether a new Shopify order gets a WhatsApp confirmation (spec 2026-10-16, slice W1):
 * one row per order, either scheduled (send_at = now + delay, SendOrderConfirmation queued for
 * then) or skipped with a reason. While the feature is off nothing is written at all.
 */
final class OrderConfirmations
{
    public function __construct(private readonly ConfirmationSettings $settings) {}

    /** @param  bool  $shopifyTest  the webhook payload's `test` flag (a Shopify test checkout) */
    public function onOrderCreated(Order $order, bool $shopifyTest = false): ?OrderConfirmation
    {
        if (! $this->settings->enabled()) {
            return null;
        }

        if (($existing = OrderConfirmation::where('order_id', $order->id)->first()) !== null) {
            return $existing;
        }

        $account = $this->whatsAppAccount();
        $phone = $this->phoneFor($order);
        $reason = $this->skipReason($order, $shopifyTest, $phone, $account);

        $confirmation = OrderConfirmation::createOrFirst(['order_id' => $order->id], [
            'channel_account_id' => $account?->id,
            'customer_id' => $order->customer_id,
            'phone_e164' => $phone,
            'status' => $reason === null ? OrderConfirmationStatus::Scheduled : OrderConfirmationStatus::Skipped,
            'skip_reason' => $reason?->value,
            'send_at' => $reason === null ? now()->addMinutes($this->settings->delayMinutes()) : null,
        ]);

        // A duplicate delivery that lost the insert race gets the winner's row and schedules nothing.
        if ($confirmation->wasRecentlyCreated && $reason === null) {
            SendOrderConfirmation::dispatch($confirmation->id)->delay($confirmation->send_at);
        }

        return $confirmation;
    }

    /**
     * The WhatsApp number confirmations go out from: connected, never a team-test or load-test
     * channel, and in production only a live one (a fake/demo account must never "send" there).
     * A live account wins over a fake one kept on file.
     */
    public function whatsAppAccount(): ?ChannelAccount
    {
        return ChannelAccount::query()
            ->where('platform', Platform::WhatsApp)
            ->where('status', 'connected')
            ->where('driver', '!=', TestScope::DRIVER)
            ->where('is_load_test', false)
            ->when(app()->isProduction(), fn ($q) => $q->where('driver', 'live'))
            ->orderByRaw("case when driver = 'live' then 0 else 1 end")
            ->orderBy('id')
            ->first();
    }

    /** Whether a confirmation may still go out from this account (re-checked right before the send). */
    public static function accountUsable(?ChannelAccount $account): bool
    {
        return $account !== null
            && $account->status === 'connected'
            && $account->driver !== TestScope::DRIVER
            && ! $account->is_load_test
            && (! app()->isProduction() || $account->isLive());
    }

    /** The first usable Egyptian mobile: shipping, then billing, then the customer's own phone. */
    public function phoneFor(Order $order): ?string
    {
        $customer = $order->customer;

        foreach ([$order->shipping_phone, $order->billing_phone, $customer?->phone, $customer?->normalized_phone] as $raw) {
            if (($e164 = EgyptPhone::toE164($raw)) !== null) {
                return $e164;
            }
        }

        return null;
    }

    private function skipReason(Order $order, bool $shopifyTest, ?string $phone, ?ChannelAccount $account): ?SkipReason
    {
        $placedAt = $order->placed_at ?? $order->created_at;

        return match (true) {
            $shopifyTest || $order->is_load_test || $this->fromTestChannel($order) => SkipReason::TestOrder,
            $order->status === OrderStatus::Cancelled || $order->cancelled_at !== null => SkipReason::Cancelled,
            DataFloor::isBefore($placedAt) => SkipReason::BeforeFloor,
            $placedAt !== null && $placedAt->lt(now()->subMinutes($this->settings->maxOrderAgeMinutes())) => SkipReason::TooOld,
            $order->source === OrderSource::Chat && $this->settings->skipChatOrders() => SkipReason::ChatOrder,
            $phone === null => SkipReason::NoPhone,
            $account === null => SkipReason::NoWhatsAppChannel,
            default => null,
        };
    }

    private function fromTestChannel(Order $order): bool
    {
        if ($order->conversation_id === null) {
            return false;
        }

        $conversation = Conversation::query()->with('channelAccount')->find($order->conversation_id);

        return $conversation !== null && ($conversation->is_test || $conversation->isLoadTest()
            || $conversation->channelAccount?->driver === TestScope::DRIVER);
    }
}
