<?php

namespace App\Bot\Flows\Steps;

use App\Bot\Flow\Orders\OrderLookup;
use App\Bot\Flow\Orders\OrderSnapshot;
use App\Bot\Flow\Orders\OrderStatusText;
use App\Bot\Flows\EntityExtractor;
use App\Bot\Flows\FlowPrompter;
use App\Models\Conversation;
use Illuminate\Support\Collection;

/**
 * Order number, mobile or email → OrderLookup. Found saves the order keys;
 * several open orders lists them and waits; not found or no details asks
 * once more, then keeps what she typed as `order_ref_text`.
 */
final class OrderStep extends BaseStep
{
    /** A customer who keeps getting "several orders" is not asked forever. */
    private const MAX_MULTIPLE_ASKS = 2;

    private const ORDER_KEYS = ['order_number', 'order_id', 'order_placed_at', 'order_status_line', 'order_status_key', 'order_governorate', 'order_failed_attempt'];

    public function __construct(
        FlowPrompter $prompter,
        private readonly OrderLookup $lookup,
        private readonly OrderStatusText $statusText,
    ) {
        parent::__construct($prompter);
    }

    public function answer(Conversation $c, array $state, array $step, string $text, Collection $burst): ?StepOutcome
    {
        $entities = array_filter([
            'order_ref' => EntityExtractor::orderRef($text),
            'phone' => EntityExtractor::phone($text),
            'email' => EntityExtractor::email($text),
        ]);

        if ($entities === []) {
            return null;
        }

        $result = $this->lookup->find($c, $entities);

        if ($result['status'] === 'found') {
            return StepOutcome::continue($this->orderData($result['snapshots'][0]));
        }

        if ($result['status'] === 'multiple') {
            if ($state['retries'] >= self::MAX_MULTIPLE_ASKS) {
                return $this->keepTyped($text);
            }

            // Same sentence as TurnRunner::applyLookup.
            $list = array_map(fn (OrderSnapshot $s) => $s->number.' ('.$s->placedAt->setTimezone(OrderStatusText::TIMEZONE)->format('j/n').')', $result['snapshots']);

            return StepOutcome::wait([['text' => 'لقيت أكتر من أوردر: '.implode('، ', $list).' تحبي أتابع أنهي واحد؟']], $state['retries'] + 1);
        }

        return $this->notFound($state, $text);
    }

    public function unresolved(Conversation $c, array $state, array $step, string $text): StepOutcome
    {
        return $this->notFound($state, $text);
    }

    private function notFound(array $state, string $text): StepOutcome
    {
        if ($state['retries'] >= 1) {
            return $this->keepTyped($text);
        }

        return StepOutcome::wait([['text' => $this->scriptText('flow_not_found_order')]], $state['retries'] + 1);
    }

    /** Continues with what she typed; order keys from an earlier pass (summary edit) are dropped. */
    private function keepTyped(string $text): StepOutcome
    {
        return StepOutcome::continue(['order_ref_text' => trim($text)] + array_fill_keys(self::ORDER_KEYS, null));
    }

    /** @return array<string, mixed> */
    private function orderData(OrderSnapshot $s): array
    {
        return [
            'order_number' => $s->number,
            'order_id' => $s->orderId,
            'order_placed_at' => $s->placedAt->toIso8601String(),
            'order_status_line' => $this->statusText->line($s),
            'order_status_key' => $s->statusKey,
            'order_governorate' => $s->governorate,
            'order_failed_attempt' => $s->failedAttempt,
            'order_ref_text' => null,
        ];
    }
}
