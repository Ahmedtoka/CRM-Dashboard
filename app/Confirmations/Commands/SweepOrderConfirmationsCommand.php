<?php

namespace App\Confirmations\Commands;

use App\Confirmations\ConfirmationSettings;
use App\Confirmations\Contracts\ConfirmationSender;
use App\Confirmations\Events\OrderConfirmationNoResponse;
use App\Confirmations\SkipReason;
use App\Confirmations\TagSync;
use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderStatus;
use App\Models\OrderConfirmation;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Every 5 minutes (spec 2026-10-16, slice W3):
 *  1. one reminder to a customer who has not answered after reminder_after_hours;
 *  2. no answer after no_response_after_hours: status no_response (the call list);
 *  3. Shopify tags that failed earlier are retried;
 *  4. a scheduled row whose send job got lost (send_at passed 15+ minutes ago) is queued again.
 * Each step only reads rows by the (status, sent_at|send_at) indexes, in chunks.
 */
final class SweepOrderConfirmationsCommand extends Command
{
    protected $signature = 'crm:order-confirmations:sweep';

    protected $description = 'Send WhatsApp confirmation reminders, move silent orders to the call list, retry Shopify tags';

    private const CHUNK = 100;

    /** A scheduled row older than this is past saving: W1's job would skip it anyway. */
    private const LOST_JOB_WINDOW_HOURS = 24;

    private const SEND_JOB = 'App\\Confirmations\\Jobs\\SendOrderConfirmation';

    /** Rows closed this run because their order was cancelled or is gone. */
    private int $skipped = 0;

    public function handle(ConfirmationSettings $settings, ConfirmationSender $sender, TagSync $tags): int
    {
        $noResponseCutoff = now()->subHours($settings->noResponseAfterHours());
        // Switched off: nothing new reaches customers and no order moves to the call list;
        // tags already owed to Shopify are still written.
        $on = $settings->enabled();
        $counts = [
            'no_response' => $on ? $this->noResponse($noResponseCutoff) : 0,
            'reminded' => $on ? $this->remind($sender, now()->subHours($settings->reminderAfterHours()), $noResponseCutoff) : 0,
            'tags_retried' => $this->retryTags($tags),
            'requeued' => $on ? $this->requeueLost() : 0,
            'skipped' => $this->skipped,
        ];

        $this->info(collect($counts)->map(fn ($n, $k) => "{$k}={$n}")->implode(' '));

        return self::SUCCESS;
    }

    /** Rows past the reminder deadline but not the no-response one get one reminder. */
    private function remind(ConfirmationSender $sender, CarbonInterface $reminderCutoff, CarbonInterface $noResponseCutoff): int
    {
        $count = 0;

        OrderConfirmation::query()
            ->status(OrderConfirmationStatus::Sent)
            ->whereNull('reminded_at')
            ->where('sent_at', '<=', $reminderCutoff)
            ->where('sent_at', '>', $noResponseCutoff)
            ->chunkById(self::CHUNK, function ($rows) use ($sender, &$count) {
                foreach ($rows as $row) {
                    $count += $this->remindOnce($sender, $row) ? 1 : 0;
                }
            });

        return $count;
    }

    /**
     * At most one reminder per row: a per-row lock, a re-read under it, and reminded_at
     * stamped here when the sender did not (or threw), so a failed reminder is never resent.
     */
    private function remindOnce(ConfirmationSender $sender, OrderConfirmation $row): bool
    {
        $lock = Cache::lock('order-confirmation-remind:'.$row->id, 120);

        if (! $lock->get()) {
            return false;
        }

        try {
            $row = $row->fresh();

            if ($row === null || $row->status !== OrderConfirmationStatus::Sent || $row->reminded_at !== null || $this->skipDeadOrder($row)) {
                return false;
            }

            $error = null;

            try {
                $sender->send($row, 'reminder');
            } catch (Throwable $e) {
                report($e);
                $error = mb_substr('Reminder: '.$e->getMessage(), 0, 500);
            }

            $fresh = $row->fresh();
            $values = ($fresh?->reminded_at === null ? ['reminded_at' => now()] : []) + ($error !== null ? ['last_error' => $error] : []);

            if ($fresh !== null && $values !== []) {
                $fresh->update($values);
            }

            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * A cancelled order (or one that is gone, e.g. hidden as a load test) gets no reminder
     * and no call: the row is closed as skipped (`cancelled` / `test_order`).
     */
    private function skipDeadOrder(OrderConfirmation $row): bool
    {
        $order = $row->order()->first();
        $reason = match (true) {
            $order === null => SkipReason::TestOrder,
            $order->status === OrderStatus::Cancelled || $order->cancelled_at !== null => SkipReason::Cancelled,
            default => null,
        };

        if ($reason === null) {
            return false;
        }

        $this->skipped += OrderConfirmation::whereKey($row->id)
            ->where('status', OrderConfirmationStatus::Sent->value)
            ->update(['status' => OrderConfirmationStatus::Skipped->value, 'skip_reason' => $reason->value, 'updated_at' => now()]);

        return true;
    }

    private function noResponse(CarbonInterface $cutoff): int
    {
        $count = 0;

        OrderConfirmation::query()
            ->status(OrderConfirmationStatus::Sent)
            ->where('sent_at', '<=', $cutoff)
            ->chunkById(self::CHUNK, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    if ($this->skipDeadOrder($row)) {
                        continue;
                    }

                    // Claimed with a guarded update: a button tap landing now wins.
                    $claimed = OrderConfirmation::whereKey($row->id)
                        ->where('status', OrderConfirmationStatus::Sent->value)
                        ->update(['status' => OrderConfirmationStatus::NoResponse->value, 'updated_at' => now()]);

                    if ($claimed === 1) {
                        OrderConfirmationNoResponse::dispatch($row->fresh());
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function retryTags(TagSync $tags): int
    {
        $count = 0;

        OrderConfirmation::query()
            ->whereNotNull('pending_tags')
            ->whereNull('tag_synced_at')
            ->with('order')
            ->chunkById(self::CHUNK, function ($rows) use ($tags, &$count) {
                foreach ($rows as $row) {
                    if (TagSync::pendingTags($row) !== [] && $tags->retry($row)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    /** Queues W1's send job again for rows it never picked up; each row at most every 15 minutes. */
    private function requeueLost(): int
    {
        if (! class_exists(self::SEND_JOB)) {
            return 0;
        }

        $cutoff = now()->subMinutes(15);
        $count = 0;

        OrderConfirmation::query()
            ->status(OrderConfirmationStatus::Scheduled)
            ->where('send_at', '<=', $cutoff)
            ->where('send_at', '>', now()->subHours(self::LOST_JOB_WINDOW_HOURS))
            ->where('updated_at', '<=', $cutoff)
            ->chunkById(self::CHUNK, function ($rows) use ($cutoff, &$count) {
                foreach ($rows as $row) {
                    $claimed = OrderConfirmation::whereKey($row->id)
                        ->where('status', OrderConfirmationStatus::Scheduled->value)
                        ->where('updated_at', '<=', $cutoff)
                        ->update(['updated_at' => now()]);

                    if ($claimed === 1) {
                        $job = self::SEND_JOB;
                        dispatch(new $job($row->id));
                        $count++;
                    }
                }
            });

        return $count;
    }
}
