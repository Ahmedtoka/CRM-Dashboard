<?php

namespace App\Bot\Flows\Steps;

use App\Bot\Flow\Orders\OrderSnapshot;
use App\Bot\Flow\Orders\OrderStatusText;
use App\Bot\Flows\FlowPrompter;
use App\Cases\CaseRecorder;
use App\Models\Conversation;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Sends the order status line saved by the `order` step. A held, returned, failed-attempt or
 * delayed order also records a `delivery_followup` case and says the team will
 * follow up. No order → handover.
 */
final class StatusStep extends BaseStep
{
    public const FOLLOW_UP_TEXT = 'هيتواصل معاكي حد من الفريق يتابع الأوردر 🌸';

    public const NO_ORDER_TEXT = 'تمام، هيتواصل معاكي حد من الفريق يتابع الأوردر 🌸';

    public function __construct(
        FlowPrompter $prompter,
        private readonly OrderStatusText $statusText,
        private readonly CaseRecorder $cases,
    ) {
        parent::__construct($prompter);
    }

    public function enter(Conversation $c, array $state, array $step): StepOutcome
    {
        $data = $state['data'];
        $line = trim((string) ($data['order_status_line'] ?? ''));

        if ($line === '') {
            return StepOutcome::handover('order_details_missing', [['text' => self::NO_ORDER_TEXT]]);
        }

        $messages = [['text' => $line]];

        if ($this->needsFollowUp($data)) {
            $this->recordFollowUp($c, $data);
            $messages[] = ['text' => self::FOLLOW_UP_TEXT];
        }

        return StepOutcome::continue([], $messages);
    }

    private function needsFollowUp(array $data): bool
    {
        $key = (string) ($data['order_status_key'] ?? '');

        // A failed delivery attempt gets the same follow-up as hold/returned (the old TurnRunner handed it over).
        if (in_array($key, ['hold', 'returned'], true) || ($data['order_failed_attempt'] ?? false) === true) {
            return true;
        }

        try {
            $snapshot = new OrderSnapshot(
                (int) ($data['order_id'] ?? 0),
                (string) ($data['order_number'] ?? ''),
                CarbonImmutable::parse((string) ($data['order_placed_at'] ?? '')),
                'shopify',
                $key,
                null,
                is_string($data['order_governorate'] ?? null) ? $data['order_governorate'] : null,
                ($data['order_failed_attempt'] ?? false) === true,
            );
        } catch (Throwable) {
            return false;
        }

        return $this->statusText->isDelayed($snapshot, CarbonImmutable::now());
    }

    private function recordFollowUp(Conversation $c, array $data): void
    {
        // A failed case write must not cost her the status answer.
        rescue(fn () => $this->cases->record($c, 'delivery_followup', $data));
    }
}
