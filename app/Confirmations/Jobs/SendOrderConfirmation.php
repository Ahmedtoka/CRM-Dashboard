<?php

namespace App\Confirmations\Jobs;

use App\Confirmations\ConfirmationSettings;
use App\Confirmations\Contracts\ConfirmationSender;
use App\Confirmations\OrderConfirmations;
use App\Confirmations\SkipReason;
use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderStatus;
use App\Models\OrderConfirmation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Sends the order confirmation template at send_at (spec 2026-10-16, slice W1). At most one send per
 * row: a per-row lock keeps two workers apart, the row must still be `scheduled` under it, and a
 * sender that throws marks the row failed instead of letting the queue retry into a second message.
 */
final class SendOrderConfirmation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** A send this late (a queue outage) is skipped: the customer may have ordered half a day ago. */
    public const MAX_LATENESS_HOURS = 3;

    public function __construct(public readonly int $confirmationId)
    {
        $this->onQueue('outbound');
    }

    public function handle(ConfirmationSender $sender, ConfirmationSettings $settings): void
    {
        $lock = Cache::lock('order-confirmation-send:'.$this->confirmationId, 120);

        if (! $lock->get()) {
            return; // another worker is sending this row right now
        }

        try {
            $this->sendOnce($sender, $settings);
        } finally {
            $lock->release();
        }
    }

    private function sendOnce(ConfirmationSender $sender, ConfirmationSettings $settings): void
    {
        $confirmation = OrderConfirmation::with(['order', 'channelAccount'])->find($this->confirmationId);

        if ($confirmation === null || $confirmation->status !== OrderConfirmationStatus::Scheduled) {
            return;
        }

        // Moved later (e.g. re-scheduled): wait for the new time instead of sending early.
        if ($confirmation->send_at !== null && $confirmation->send_at->isFuture()) {
            $this->release((int) ceil(now()->diffInSeconds($confirmation->send_at, true)));

            return;
        }

        $reason = $this->skipReason($confirmation, $settings);

        if ($reason !== null) {
            $confirmation->update(['status' => OrderConfirmationStatus::Skipped, 'skip_reason' => $reason->value]);

            return;
        }

        try {
            $sender->send($confirmation, 'confirm');
        } catch (Throwable $e) {
            report($e);
            $confirmation->update(['status' => OrderConfirmationStatus::Failed, 'last_error' => mb_substr($e->getMessage(), 0, 500)]);

            return;
        }

        // The sender owns the status; one that left the row scheduled must not be retried into a second send.
        if ($confirmation->fresh()?->status === OrderConfirmationStatus::Scheduled) {
            $confirmation->update(['status' => OrderConfirmationStatus::Failed, 'last_error' => 'The sender did not record the send.']);
        }
    }

    private function skipReason(OrderConfirmation $confirmation, ConfirmationSettings $settings): ?SkipReason
    {
        $order = $confirmation->order;

        return match (true) {
            ! $settings->enabled() => SkipReason::Disabled,
            $confirmation->send_at !== null && $confirmation->send_at->lt(now()->subHours(self::MAX_LATENESS_HOURS)) => SkipReason::TooLate,
            $order === null => SkipReason::TestOrder, // a load-test order (hidden by Order's scope) or a deleted one
            $order->status === OrderStatus::Cancelled || $order->cancelled_at !== null => SkipReason::Cancelled,
            ! OrderConfirmations::accountUsable($confirmation->channelAccount) => SkipReason::NoWhatsAppChannel,
            default => null,
        };
    }
}
