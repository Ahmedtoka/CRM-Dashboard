<?php

namespace App\Bot\Flows\Steps;

use App\Bot\Flow\Orders\DeliveryEstimate;
use App\Bot\Flow\Orders\OrderStatusText;
use App\Bot\Flows\FlowAnswerResolver;
use App\Bot\Flows\FlowPrompter;
use App\Models\Conversation;
use App\Models\Fulfillment;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The order status card (the owner's tracking flow, 2026-09-19): the order found
 * by the `order` step as "أهلاً يا … أوردر #… (اتطلب يوم … — … قطع) / 📦 الحالة /
 * 🚚 متوقع يوصل / 🔗 تتبع الشحنة", with the step's options as buttons.
 *
 * On entry it keeps the card values in the flow data — `order_date`, `order_items`,
 * `order_status`, `order_eta` (DeliveryEstimate; none for a delivered, cancelled,
 * held or returned order), `order_tracking` (the latest fulfillment's tracking link,
 * else its number; only for a proven owner and an order still on its way), `order_stage` (`open`/`finished`) and
 * `order_late` (`overdue` once the window passed, a failed delivery attempt, held or
 * returned; else `on_time`) — so the text and the later steps can use them.
 *
 * It never records anything by itself (the old step opened a delivery follow-up for
 * every "late" order): only the options decide. An option may carry `when`
 * (`open` | `finished`: shown only for an order still on its way / delivered or
 * cancelled), its own `next` step, or an `action` (`flow:<key>` with the order
 * carried, `handover`); one with neither goes on by the step's branches (e.g. on
 * `order_late`). A status step without options sends the card and goes on.
 * No order → handover.
 */
final class StatusStep extends BaseStep
{
    public const CARD_TEXT = "أهلاً يا {customer_first_name} 🌸 أوردر #{order_number} (اتطلب يوم {order_date} — {order_items})\n📦 الحالة: {order_status}\n🚚 متوقع يوصل: {order_eta}\n🔗 تتبع الشحنة: {order_tracking}";

    public const NO_ORDER_TEXT = 'تمام، هيتواصل معاكي حد من الفريق يتابع الأوردر 🌸';

    /** Orders that are no longer on their way. */
    public const FINISHED = ['delivered', 'cancelled'];

    /** Open orders that need the team whatever the date says. */
    private const NEEDS_TEAM = ['hold', 'returned'];

    public function __construct(
        FlowPrompter $prompter,
        private readonly FlowAnswerResolver $resolver,
        private readonly DeliveryEstimate $estimate,
    ) {
        parent::__construct($prompter);
    }

    public function enter(Conversation $c, array $state, array $step): StepOutcome
    {
        $data = $state['data'];

        if (blank($data['order_number'] ?? null) || blank($data['order_status_key'] ?? null)) {
            return StepOutcome::handover('order_details_missing', [['text' => self::NO_ORDER_TEXT]]);
        }

        $card = $this->card($data);
        $state['data'] = array_filter($card, fn ($v) => $v !== null) + $data;
        $prompt = $this->prompt($state, $step);

        if ($this->options($state, $step) === []) {
            return StepOutcome::continue($card, [$prompt]);
        }

        return StepOutcome::wait([$prompt], 0, $card);
    }

    public function prompt(array $state, array $step): array
    {
        $data = $state['data'] ?? [];
        $text = trim((string) ($step['text'] ?? '')) !== '' ? (string) $step['text'] : self::CARD_TEXT;
        $buttons = array_map(
            fn (array $o) => self::button((string) $o['title'], "step:{$state['key']}:{$state['step']}:{$o['value']}"),
            $this->options($state, $step),
        );

        return ['text' => $this->prompter->renderText($text, $data), 'buttons' => $buttons];
    }

    public function answer(Conversation $c, array $state, array $step, string $text, Collection $burst): ?StepOutcome
    {
        $options = $this->options($state, $step);
        $value = trim($text);

        // The interpreter answers with an option value; a question goes to the interpreter first.
        $option = collect($options)->first(fn (array $o) => (string) $o['value'] === $value)
            ?? ($this->resolver->isQuestion($text) ? null : $this->resolver->matchOption($options, $text));

        return $option !== null ? $this->pick($step, $option) : null;
    }

    public function payload(Conversation $c, array $state, array $step, string $value): ?StepOutcome
    {
        $option = collect($this->options($state, $step))->first(fn (array $o) => (string) $o['value'] === $value);

        return $option !== null ? $this->pick($step, $option) : null;
    }

    /**
     * The options shown for this order: `when: open` only while it is on its way,
     * `when: finished` only once delivered or cancelled.
     *
     * @return list<array<string, mixed>>
     */
    private function options(array $state, array $step): array
    {
        $stage = in_array((string) ($state['data']['order_status_key'] ?? ''), self::FINISHED, true) ? 'finished' : 'open';

        return array_values(array_filter(
            is_array($step['options'] ?? null) ? $step['options'] : [],
            fn ($o) => is_array($o) && filled($o['value'] ?? null) && filled($o['title'] ?? null)
                && in_array($o['when'] ?? $stage, [$stage], true),
        ));
    }

    private function pick(array $step, array $option): StepOutcome
    {
        $field = (string) ($step['field'] ?? '');
        $data = $field !== '' ? [$field => (string) $option['value'], $field.'_title' => (string) $option['title']] : [];
        $next = is_string($option['next'] ?? null) && $option['next'] !== '' ? $option['next'] : null;
        $action = is_string($option['action'] ?? null) && trim($option['action']) !== '' ? trim($option['action']) : null;

        if ($next === null && $action !== null) {
            return StepOutcome::jump($action, $data);
        }

        return StepOutcome::continue($data, [], $next);
    }

    /** @return array<string, string|null> the card values (null removes a stale one) */
    private function card(array $data): array
    {
        $key = (string) $data['order_status_key'];
        $failed = ($data['order_failed_attempt'] ?? false) === true;
        $finished = in_array($key, self::FINISHED, true);
        $order = is_numeric($data['order_id'] ?? null) ? Order::with('items')->find((int) $data['order_id']) : null;
        $owner = ($data['order_verified'] ?? null) === true;
        $placed = $this->placedAt($data, $order);

        $window = ! $finished && ! in_array($key, self::NEEDS_TEAM, true) && $placed !== null
            ? $this->estimate->window($placed, $this->estimate->isMainCity($order, is_string($data['order_governorate'] ?? null) ? $data['order_governorate'] : null))
            : null;

        $late = match (true) {
            $finished => null,
            $failed || in_array($key, self::NEEDS_TEAM, true) => 'overdue',
            $window !== null && $this->estimate->overdue($window, CarbonImmutable::now()) => 'overdue',
            default => 'on_time',
        };

        return [
            'order_date' => $placed !== null ? DeliveryEstimate::dayLabel($placed) : null,
            'order_items' => $order !== null ? self::itemsCount((int) $order->items->sum('qty')) : null,
            'order_status' => OrderStatusText::cardLine($key, $failed),
            'order_eta' => $window !== null ? DeliveryEstimate::text($window) : null,
            'order_tracking' => $owner && ! $finished && $order !== null ? $this->tracking($order) : null,
            'order_stage' => $finished ? 'finished' : 'open',
            'order_late' => $late,
        ];
    }

    private function placedAt(array $data, ?Order $order): ?CarbonImmutable
    {
        try {
            if (filled($data['order_placed_at'] ?? null)) {
                return CarbonImmutable::parse((string) $data['order_placed_at']);
            }
        } catch (Throwable) {
            // fall back to the order row
        }

        $at = $order?->placed_at ?? $order?->created_at;

        return $at !== null ? CarbonImmutable::instance($at) : null;
    }

    /** The latest fulfillment's tracking link, else its tracking number. */
    private function tracking(Order $order): ?string
    {
        $fulfillments = $order->fulfillments()->orderByDesc('shopify_created_at')->orderByDesc('id')->get(['tracking_url', 'tracking_number']);

        $url = $fulfillments->first(fn (Fulfillment $f) => filled($f->tracking_url))?->tracking_url;

        return $url ?? $fulfillments->first(fn (Fulfillment $f) => filled($f->tracking_number))?->tracking_number;
    }

    /** "قطعة واحدة" / "قطعتين" / "3 قطع" / "11 قطعة"; null when the order has no items. */
    public static function itemsCount(int $n): ?string
    {
        return match (true) {
            $n <= 0 => null,
            $n === 1 => 'قطعة واحدة',
            $n === 2 => 'قطعتين',
            $n <= 10 => $n.' قطع',
            default => $n.' قطعة',
        };
    }
}
