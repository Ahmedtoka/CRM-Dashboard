<?php

namespace App\Bot\Flows\Steps;

use App\Bot\ArabicNormalizer;
use App\Bot\Flow\Orders\OrderLookup;
use App\Bot\Flow\Orders\OrderSnapshot;
use App\Bot\Flow\Orders\OrderStatusText;
use App\Bot\Flows\EntityExtractor;
use App\Bot\Flows\FlowPrompter;
use App\Bot\Flows\Returns\ReturnItems;
use App\Models\Conversation;
use App\Models\Order;
use Illuminate\Support\Collection;

/**
 * Order number, mobile or email → OrderLookup. Found saves the order keys;
 * several open orders lists them and waits; not found or no details asks
 * once more, then keeps what she typed as `order_ref_text`.
 *
 * With `verify_owner: true` (spec 2026-09-19 §1, option A) an order found by
 * its number alone is only accepted once she proves it is hers: the last 4
 * digits of the order's mobile, 2 tries, then a handover
 * (`order_verification_failed`). Before that the bot only says it found the
 * order, nothing else about it. A mobile/email lookup, or a conversation
 * already linked to the order's customer, is proof by itself. The proof is
 * kept as `order_verified` (+ `verified_order_ids`) so it is not asked again.
 * Once proven, the step also keeps `customer_first_name` (the order's customer)
 * and `order_window` (`open`/`closed`: the 14 days from delivery) for the
 * return/exchange greeting and its branches.
 *
 * 2026-09-19 (order tracking): several open orders on her mobile/email come
 * with one button each ("#1047 · 8/9", up to 13); a tap picks that order. A
 * flow opened from another flow's option (`flow:<key>`) receives the verified
 * order (self::carried) and this step is then skipped: she is not asked again.
 */
final class OrderStep extends BaseStep
{
    /** A customer who keeps getting "several orders" is not asked forever. */
    private const MAX_MULTIPLE_ASKS = 2;

    private const MAX_VERIFY_TRIES = 2;

    /** Messenger's quick-reply limit: at most this many orders are offered as buttons. */
    public const MAX_ORDER_BUTTONS = 13;

    public const VERIFY_TEXT = 'لقيت الأوردر 🌸 للتأكيد، اكتبي آخر ٤ أرقام من الموبايل اللي طلبتي بيه';

    public const VERIFY_RETRY_TEXT = 'الأرقام دي مش مطابقة 🙏 جربي تاني، اكتبي آخر ٤ أرقام من الموبايل اللي طلبتي بيه';

    // The handover reply that follows says she is with the team (flow 7, 2026-09-19).
    public const VERIFY_FAILED_TEXT = 'مش قادر أتأكد من الأوردر ده 🙏';

    /** `order_editable`: still at the company (cancel/edit possible), already shipped, or cancelled. */
    public const EDITABLE = 'yes';

    public const NOT_EDITABLE = 'no';

    public const CANCELLED = 'cancelled';

    /** Status keys of an order that has not left the company yet (OrderLookup / the OMS). */
    private const UNSHIPPED_KEYS = ['confirmed', 'prepared'];

    private const ORDER_KEYS = ['order_number', 'order_id', 'order_placed_at', 'order_status_line', 'order_status_key', 'order_governorate', 'order_failed_attempt', 'customer_first_name', 'order_window', 'order_editable'];

    /** What another flow receives of a verified order (FlowEngine jump to `flow:<key>`). */
    private const CARRIED_KEYS = [...self::ORDER_KEYS, 'order_verified', 'verified_order_ids'];

    /**
     * The verified order of a flow's data, to start another flow with; empty when she has
     * not proven an order is hers (nothing is carried then, the next flow asks as usual).
     *
     * @return array<string, mixed>
     */
    public static function carried(array $data): array
    {
        if (! self::hasVerifiedOrder($data)) {
            return [];
        }

        return array_filter(array_intersect_key($data, array_flip(self::CARRIED_KEYS)), fn ($v) => $v !== null);
    }

    /** A proven order is already in the flow data (carried from another flow). */
    public static function hasVerifiedOrder(array $data): bool
    {
        return ($data['order_verified'] ?? null) === true && is_numeric($data['order_id'] ?? null) && filled($data['order_number'] ?? null);
    }

    public function enter(Conversation $c, array $state, array $step): StepOutcome
    {
        // Carried over from the flow she came from (e.g. tracking to cancel/edit): never asked twice.
        if (self::hasVerifiedOrder($state['data'] ?? []) && ! $this->verifying($state, $step)) {
            return StepOutcome::continue();
        }

        return parent::enter($c, $state, $step);
    }

    /** A tap on one of the listed orders (`pick:<order id>`): only an order that was offered to her. */
    public function payload(Conversation $c, array $state, array $step, string $value): ?StepOutcome
    {
        if (! str_starts_with($value, 'pick:') || $this->verifying($state, $step)) {
            return null;
        }

        $id = (int) substr($value, 5);
        $offered = array_map('intval', (array) ($state['data']['order_choices'] ?? []));
        $order = in_array($id, $offered, true) ? Order::with('customer')->find($id) : null;

        if ($order === null) {
            return null;
        }

        // The list came from her own mobile/email: ownership is already proven.
        $snapshot = $this->lookup->ownedSnapshot($order);

        return StepOutcome::continue($this->verifies($step) ? $this->verifiedData($state, $snapshot) : $this->orderData($snapshot));
    }

    public function __construct(
        FlowPrompter $prompter,
        private readonly OrderLookup $lookup,
        private readonly OrderStatusText $statusText,
        private readonly ReturnItems $returns,
    ) {
        parent::__construct($prompter);
    }

    public function prompt(array $state, array $step): array
    {
        if ($this->verifying($state, $step)) {
            return ['text' => self::VERIFY_TEXT, 'buttons' => []];
        }

        return parent::prompt($state, $step);
    }

    public function answer(Conversation $c, array $state, array $step, string $text, Collection $burst): ?StepOutcome
    {
        if ($this->verifying($state, $step)) {
            return $this->checkLastDigits($state, $text);
        }

        $entities = array_filter([
            'order_ref' => EntityExtractor::orderRef($text),
            'phone' => EntityExtractor::phone($text),
            'email' => EntityExtractor::email($text),
        ]);

        if ($entities === []) {
            return null;
        }

        // The mobile/email that listed several orders still counts when she then names one of them.
        foreach ((array) ($state['data']['order_lookup_contact'] ?? []) as $key => $value) {
            if (in_array($key, ['phone', 'email'], true) && is_string($value) && ! isset($entities[$key])) {
                $entities[$key] = $value;
            }
        }

        $result = $this->lookup->find($c, $entities, self::MAX_ORDER_BUTTONS);

        if ($result['status'] === 'found') {
            $snapshot = $result['snapshots'][0];

            if (! $this->verifies($step)) {
                return StepOutcome::continue($this->orderData($snapshot));
            }

            return $this->owned($state, $snapshot)
                ? StepOutcome::continue($this->verifiedData($state, $snapshot))
                : $this->askLastDigits($snapshot);
        }

        if ($result['status'] === 'multiple') {
            if ($state['retries'] >= self::MAX_MULTIPLE_ASKS) {
                return $this->keepTyped($text);
            }

            // Same sentence as TurnRunner::applyLookup.
            $list = array_map(fn (OrderSnapshot $s) => $s->number.' ('.$s->placedAt->setTimezone(OrderStatusText::TIMEZONE)->format('j/n').')', $result['snapshots']);
            $contact = array_intersect_key($entities, array_flip(['phone', 'email']));
            $buttons = array_map(
                fn (OrderSnapshot $s) => self::button($s->number.' · '.$s->placedAt->setTimezone(OrderStatusText::TIMEZONE)->format('j/n'), "step:{$state['key']}:{$state['step']}:pick:{$s->orderId}"),
                $result['snapshots'],
            );

            return StepOutcome::wait(
                [['text' => 'لقيت أكتر من أوردر: '.implode('، ', $list).' تحبي أتابع أنهي واحد؟', 'buttons' => $buttons]],
                $state['retries'] + 1,
                ['order_choices' => array_map(fn (OrderSnapshot $s) => $s->orderId, $result['snapshots'])] + ($contact !== [] ? ['order_lookup_contact' => $contact] : []),
            );
        }

        return $this->notFound($state, $text);
    }

    public function unresolved(Conversation $c, array $state, array $step, string $text): StepOutcome
    {
        if ($this->verifying($state, $step)) {
            return $this->wrongDigits($state);
        }

        return $this->notFound($state, $text);
    }

    private function verifies(array $step): bool
    {
        return ($step['verify_owner'] ?? false) === true;
    }

    /** Waiting for the last 4 digits of the found order's mobile. */
    private function verifying(array $state, array $step): bool
    {
        return $this->verifies($step) && is_array($state['data']['order_verify'] ?? null);
    }

    private function owned(array $state, OrderSnapshot $s): bool
    {
        return $s->ownerVerified || in_array($s->orderId, array_map('intval', (array) ($state['data']['verified_order_ids'] ?? [])), true);
    }

    /** Only "found it" and the question: no number, name, address or items before the proof. */
    private function askLastDigits(OrderSnapshot $s): StepOutcome
    {
        $order = Order::with('customer')->find($s->orderId);

        if ($order === null || $this->lookup->orderPhoneDigits($order) === []) {
            return StepOutcome::handover('order_verification_failed', [['text' => self::VERIFY_FAILED_TEXT]]);
        }

        return StepOutcome::wait(
            [['text' => self::VERIFY_TEXT]],
            0,
            ['order_verify' => ['order_id' => $s->orderId, 'tries' => 0], 'order_verified' => null, 'order_ref_text' => null] + array_fill_keys(self::ORDER_KEYS, null),
        );
    }

    private function checkLastDigits(array $state, string $text): ?StepOutcome
    {
        $digits = preg_replace('/\D+/', '', (new ArabicNormalizer)->digitsToLatin($text)) ?? '';

        if ($digits === '') {
            return null;
        }

        $order = Order::with('customer')->find((int) ($state['data']['order_verify']['order_id'] ?? 0));

        if ($order === null) {
            return StepOutcome::handover('order_verification_failed', [['text' => self::VERIFY_FAILED_TEXT]]);
        }

        $last4 = strlen($digits) >= 4 ? substr($digits, -4) : null;
        $matches = $last4 !== null && collect($this->lookup->orderPhoneDigits($order))->contains(fn (string $p) => substr($p, -4) === $last4);

        if (! $matches) {
            return $this->wrongDigits($state);
        }

        return StepOutcome::continue($this->verifiedData($state, $this->lookup->ownedSnapshot($order)) + ['order_verify' => null]);
    }

    private function wrongDigits(array $state): StepOutcome
    {
        $pending = (array) $state['data']['order_verify'];
        $tries = (int) ($pending['tries'] ?? 0) + 1;

        if ($tries >= self::MAX_VERIFY_TRIES) {
            return StepOutcome::handover('order_verification_failed', [['text' => self::VERIFY_FAILED_TEXT]]);
        }

        return StepOutcome::wait([['text' => self::VERIFY_RETRY_TEXT]], 0, ['order_verify' => ['tries' => $tries] + $pending]);
    }

    /** @return array<string, mixed> the order keys plus the proof, remembered for the rest of the flow */
    private function verifiedData(array $state, OrderSnapshot $s): array
    {
        $ids = array_map('intval', (array) ($state['data']['verified_order_ids'] ?? []));

        $order = Order::with('customer')->find($s->orderId);

        return $this->orderData($s) + [
            // Only once she proved the order is hers (2026-09-19): the greeting's name and the 14-day branch.
            'customer_first_name' => $order !== null ? self::firstName($order) : null,
            'order_window' => $order !== null && $this->returns->windowClosed($order) ? 'closed' : 'open',
            'order_verified' => true,
            'verified_order_ids' => array_values(array_unique([...$ids, $s->orderId])),
            'order_lookup_contact' => null,
        ];
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
        return StepOutcome::continue(['order_ref_text' => trim($text), 'order_verified' => null, 'order_lookup_contact' => null, 'order_choices' => null] + array_fill_keys(self::ORDER_KEYS, null));
    }

    /** The first word of the order's shipping name, else of its customer's name; null when neither is known. */
    public static function firstName(Order $order): ?string
    {
        foreach ([$order->shipping_name, $order->customer?->name] as $name) {
            $first = preg_split('/\s+/u', trim((string) $name))[0] ?? '';

            if ($first !== '') {
                return $first;
            }
        }

        return null;
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
            'order_editable' => self::editable($s->statusKey, Order::find($s->orderId)),
            'order_ref_text' => null,
            'order_choices' => null,
        ];
    }

    /**
     * Whether the order can still be cancelled or changed (the owner's cancel/edit flow,
     * 2026-09-19): only while it has not been shipped, i.e. still at the company. Any
     * fulfillment (a partial one too) counts as shipped; a cancelled order is `cancelled`.
     */
    public static function editable(string $statusKey, ?Order $order): string
    {
        if ($statusKey === 'cancelled' || $order?->cancelled_at !== null) {
            return self::CANCELLED;
        }

        $fulfilled = $order !== null && (in_array($order->fulfillment_status, ['fulfilled', 'partial'], true) || $order->fulfillments()->exists());

        return in_array($statusKey, self::UNSHIPPED_KEYS, true) && ! $fulfilled ? self::EDITABLE : self::NOT_EDITABLE;
    }
}
