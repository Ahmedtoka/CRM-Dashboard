<?php

namespace App\Bot\Flows\Steps;

use App\Bot\Flow\Orders\OrderStatusText;
use App\Bot\Flows\FlowAnswerResolver;
use App\Bot\Flows\FlowPrompter;
use App\Bot\Flows\Returns\ItemSelection;
use App\Bot\Flows\Returns\ReturnItems;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * `order_items` (spec 2026-09-19 §2): lists the verified order's items and lets
 * her pick one or more — a tap (then "another one?"), typed numbers ("1 و 3",
 * "الكل", "١"), or part of a title — asks how many when a line has more than
 * one, and judges each pick straight away: never-returnable keywords are
 * refused, a discounted item is exchange only, and an item past the 14 days
 * from delivery is not added (she is offered a person). The picks are saved as
 * `selected_items`. Without a verified order, or without synced items, she
 * types the item's name instead. Nothing about the order is shown unless the
 * `order` step proved she owns it (`order_verified`).
 *
 * Step state lives in `data.items_pending` = {mode: pick|more|qty|fallback, queue?: ids, qty_for?: id}.
 */
final class OrderItemsStep extends BaseStep
{
    public const DEFAULT_TEXT = 'اختاري القطعة اللي عايزة ترجعيها أو تبدليها 👇';

    public const FALLBACK_TEXT = 'اكتبي اسم القطعة اللي عايزة ترجعيها أو تبدليها 🌸';

    public const MULTI_TEXT = 'اكتبي أرقام القطع اللي عايزاها، مثلًا: 1 و 3';

    public const MORE_QUESTION = 'تحبي تضيفي قطعة تانية؟';

    public const OTHER_QUESTION = 'تحبي تختاري قطعة تانية؟';

    public const QTY_QUESTION = 'كام قطعة؟';

    public const ALREADY_TEXT = '«%s» موجودة في اختياراتك خلاص 🌸';

    public const NOTHING_TEXT = 'تمام 🌸 لو احتجتي أي حاجة تانية أنا موجودة';

    public const MULTI_BUTTON = 'كذا قطعة';

    public const YES_BUTTON = 'أيوه';

    public const DONE_BUTTON = 'لأ كده تمام';

    public const OTHER_BUTTON = 'قطعة تانية';

    public const HUMAN_BUTTON = 'كلم موظف';

    /** Messenger: 13 quick replies. One slot stays for "كذا قطعة". */
    private const MAX_ITEM_BUTTONS = 12;

    /** Replies that end the picking ("that's all"). */
    private const DONE_WORDS = ['لا', 'لاء', 'لا كده تمام', 'لا كدا تمام', 'كده تمام', 'كدا تمام', 'خلاص', 'بس كده', 'بس كدا', 'بس', 'لا شكرا', 'مفيش', 'no', 'تمام كده'];

    private const MONTHS = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

    public function __construct(
        FlowPrompter $prompter,
        private readonly ReturnItems $items,
        private readonly ItemSelection $selection,
        private readonly FlowAnswerResolver $resolver,
    ) {
        parent::__construct($prompter);
    }

    public function enter(Conversation $c, array $state, array $step): StepOutcome
    {
        $order = $this->order($state['data']);

        if ($order === null || $order->items->isEmpty()) {
            return StepOutcome::wait([['text' => self::FALLBACK_TEXT]], 0, ['items_pending' => ['mode' => 'fallback'], 'selected_items' => null]);
        }

        return StepOutcome::wait([$this->listMessage($state, $step, $order, [], true)], 0, ['items_pending' => ['mode' => 'pick'], 'selected_items' => null]);
    }

    public function prompt(array $state, array $step): array
    {
        $pending = $this->pending($state['data']);
        $order = $this->order($state['data']);

        if ($pending['mode'] === 'fallback' || $order === null || $order->items->isEmpty()) {
            return ['text' => self::FALLBACK_TEXT, 'buttons' => []];
        }

        return match ($pending['mode']) {
            'more' => $this->moreMessage($state),
            'qty' => $this->qtyMessage($state, $order->items->firstWhere('id', (int) ($pending['qty_for'] ?? 0))),
            default => $this->listMessage($state, $step, $order, $this->selected($state['data']), false),
        };
    }

    public function answer(Conversation $c, array $state, array $step, string $text, Collection $burst): ?StepOutcome
    {
        $text = trim($text);
        $pending = $this->pending($state['data']);
        $order = $this->order($state['data']);

        if ($text === '') {
            return null;
        }

        if ($pending['mode'] === 'fallback' || $order === null || $order->items->isEmpty()) {
            return $this->typedItem($state, $text);
        }

        if ($pending['mode'] === 'qty') {
            $item = $order->items->firstWhere('id', (int) ($pending['qty_for'] ?? 0));
            $qty = $item !== null ? $this->selection->quantity($text, (int) $item->qty) : null;

            return $qty !== null ? $this->withQuantity($state, $order, $item, $qty) : null;
        }

        $selected = $this->selected($state['data']);

        if ($pending['mode'] === 'more') {
            // "تمام" after "another one?" means that's all.
            if ($this->isDone($text) || $this->resolver->clean($text) === 'تمام') {
                return $this->finish($state, $selected);
            }

            if ($this->resolver->yesNo($text) === 'yes') {
                return $this->showList($state, $step, $order);
            }
        } elseif ($this->isDone($text)) {
            return $this->finish($state, $selected);
        }

        $picked = $this->selection->positions($text, $order->items->count());

        if ($picked === ItemSelection::ALL) {
            return $this->pick($state, $order, $order->items->pluck('id')->all());
        }

        if (is_array($picked)) {
            return $this->pick($state, $order, array_map(fn (int $n) => (int) $order->items[$n - 1]->id, $picked));
        }

        $titles = $order->items->mapWithKeys(fn (OrderItem $i) => [$i->id => trim($i->title.' '.($this->items->variantOf($i) ?? ''))])->all();
        $match = $this->selection->matchTitle($text, $titles);

        return $match !== null ? $this->pick($state, $order, [$match]) : null;
    }

    public function payload(Conversation $c, array $state, array $step, string $value): ?StepOutcome
    {
        $order = $this->order($state['data']);
        $pending = $this->pending($state['data']);

        if ($value === 'done') {
            return $this->finish($state, $this->selected($state['data']));
        }

        if ($order === null || $order->items->isEmpty()) {
            return null;
        }

        if ($value === 'more') {
            return $this->showList($state, $step, $order);
        }

        if ($value === 'multi') {
            return StepOutcome::wait([['text' => self::MULTI_TEXT]], 0, ['items_pending' => ['mode' => 'pick']]);
        }

        if (str_starts_with($value, 'item:') && ctype_digit(substr($value, 5))) {
            $id = (int) substr($value, 5);

            return $order->items->contains('id', $id) ? $this->pick($state, $order, [$id]) : null;
        }

        if (str_starts_with($value, 'qty:') && $pending['mode'] === 'qty' && ctype_digit(substr($value, 4))) {
            $item = $order->items->firstWhere('id', (int) ($pending['qty_for'] ?? 0));
            $qty = (int) substr($value, 4);

            return $item !== null && $qty >= 1 && $qty <= (int) $item->qty ? $this->withQuantity($state, $order, $item, $qty) : null;
        }

        return null;
    }

    /** The verified order with its items, or null (not found, or ownership not proven). */
    private function order(array $data): ?Order
    {
        if (($data['order_verified'] ?? false) !== true || ! is_numeric($data['order_id'] ?? null)) {
            return null;
        }

        return Order::query()->with(['items' => fn ($q) => $q->orderBy('id'), 'items.variant.product', 'customer'])->find((int) $data['order_id']);
    }

    /** @return array{mode:string, queue?:list<int>, qty_for?:int} */
    private function pending(array $data): array
    {
        $p = $data['items_pending'] ?? null;

        return is_array($p) && is_string($p['mode'] ?? null) ? $p : ['mode' => 'pick'];
    }

    /** @return list<array<string, mixed>> */
    private function selected(array $data): array
    {
        return array_values(array_filter((array) ($data['selected_items'] ?? []), 'is_array'));
    }

    private function isDone(string $text): bool
    {
        return in_array($this->resolver->clean($text), array_map(fn ($w) => $this->resolver->clean($w), self::DONE_WORDS), true)
            || $this->resolver->yesNo($text) === 'no';
    }

    private function showList(array $state, array $step, Order $order): StepOutcome
    {
        return StepOutcome::wait([$this->listMessage($state, $step, $order, $this->selected($state['data']), false)], 0, ['items_pending' => ['mode' => 'pick']]);
    }

    /**
     * Judges the picked items in order; stops at the first one that needs a quantity.
     *
     * @param  list<int>  $queue  order item ids
     * @param  list<array{text:string, buttons?:list<array{title:string, payload:string}>}>  $messages
     */
    private function pick(array $state, Order $order, array $queue, array $messages = []): StepOutcome
    {
        $selected = $this->selected($state['data']);
        $added = [];
        $refused = false;
        $late = false;
        $lateText = null;

        while ($queue !== []) {
            $id = (int) array_shift($queue);
            $item = $order->items->firstWhere('id', $id);

            if (! $item instanceof OrderItem) {
                continue;
            }

            $title = $this->shortTitle($item);

            if (collect($selected)->contains(fn ($s) => (int) ($s['line_item_id'] ?? 0) === $id)) {
                $messages[] = ['text' => sprintf(self::ALREADY_TEXT, $title)];

                continue;
            }

            if (($keyword = $this->items->nonReturnableKeyword($item)) !== null) {
                $messages[] = ['text' => $this->refusalText($title, $keyword)];
                $refused = true;

                continue;
            }

            if ($this->items->windowClosed($order)) {
                $lateText = "«{$title}» عدّى على استلامها أكتر من ".ReturnItems::RETURN_DAYS.' يوم، والمرتجع والاستبدال عندنا خلال '.ReturnItems::RETURN_DAYS.' يوم من الاستلام بس 🙏';
                $late = true;

                continue;
            }

            if ((int) $item->qty > 1) {
                if ($added !== []) {
                    $messages[] = ['text' => $this->addedText($added)];
                }

                return StepOutcome::wait(
                    [...$messages, $this->qtyMessage($state, $item)],
                    0,
                    ['selected_items' => $selected, 'items_pending' => ['mode' => 'qty', 'qty_for' => $id, 'queue' => array_values($queue)]],
                );
            }

            $row = $this->row($item, 1);
            $selected[] = $row;
            $added[] = $row;

            if ($row['exchange_only']) {
                $messages[] = ['text' => $this->discountText($title)];
            }
        }

        return $this->afterPicking($state, $order, $selected, $added, $messages, $refused, $late ? $lateText : null);
    }

    private function withQuantity(array $state, Order $order, OrderItem $item, int $qty): StepOutcome
    {
        $selected = $this->selected($state['data']);
        $row = $this->row($item, $qty);
        $selected[] = $row;
        $messages = $row['exchange_only'] ? [['text' => $this->discountText($this->shortTitle($item))]] : [];
        $queue = array_map('intval', (array) ($this->pending($state['data'])['queue'] ?? []));
        $state['data']['selected_items'] = $selected;

        if ($queue !== []) {
            $messages[] = ['text' => $this->addedText([$row])];

            return $this->pick($state, $order, $queue, $messages);
        }

        return $this->afterPicking($state, $order, $selected, [$row], $messages, false, null);
    }

    /**
     * @param  list<array<string, mixed>>  $selected
     * @param  list<array<string, mixed>>  $added  added by this reply
     */
    private function afterPicking(array $state, Order $order, array $selected, array $added, array $messages, bool $refused, ?string $lateText): StepOutcome
    {
        $data = ['selected_items' => $selected];
        // Items she could still pick: not picked yet and not refused by the keyword list.
        $left = $order->items->reject(fn (OrderItem $i) => collect($selected)->contains(fn ($s) => (int) ($s['line_item_id'] ?? 0) === (int) $i->id)
            || $this->items->nonReturnableKeyword($i) !== null);

        // The window is the order's, so every other item is past it too: a person, or stop here.
        if ($lateText !== null) {
            $buttons = [self::button(self::HUMAN_BUTTON, 'handover'), $this->stepButton($state, self::DONE_BUTTON, 'done')];

            if ($added !== []) {
                $messages[] = ['text' => $this->addedText($added)];
            }

            $messages[] = ['text' => $lateText."\nتحبي أحوّلك لحد من الفريق؟", 'buttons' => $buttons];

            return StepOutcome::wait($messages, 0, $data + ['items_pending' => ['mode' => 'more']]);
        }

        if ($added !== [] && $left->isEmpty()) {
            $messages[] = ['text' => $this->addedText($added)];

            return $this->finish($state, $selected, $messages);
        }

        if ($added !== []) {
            $messages[] = ['text' => $this->addedText($added)."\n".self::MORE_QUESTION, 'buttons' => $this->moreButtons($state)];

            return StepOutcome::wait($messages, 0, $data + ['items_pending' => ['mode' => 'more']]);
        }

        // Nothing new: refused or already picked.
        if ($left->isEmpty() && $selected !== []) {
            return $this->finish($state, $selected, $messages);
        }

        $question = $refused || $selected === [] ? self::OTHER_QUESTION : self::MORE_QUESTION;
        $buttons = [$this->stepButton($state, $refused ? self::OTHER_BUTTON : self::YES_BUTTON, 'more'), $this->stepButton($state, self::DONE_BUTTON, 'done')];
        $messages[] = ['text' => $question, 'buttons' => $buttons];

        return StepOutcome::wait($messages, 0, $data + ['items_pending' => ['mode' => 'more']]);
    }

    /** @param  list<array<string, mixed>>  $selected */
    private function finish(array $state, array $selected, array $messages = []): StepOutcome
    {
        if ($selected === []) {
            return StepOutcome::end([...$messages, ['text' => self::NOTHING_TEXT, 'buttons' => [FlowPrompter::MAIN_MENU_BUTTON]]]);
        }

        return StepOutcome::continue(['selected_items' => array_values($selected), 'items_pending' => null], $messages);
    }

    /** No verified order or no synced items: the item name she types is kept as-is. */
    private function typedItem(array $state, string $text): StepOutcome
    {
        if ($this->isDone($text)) {
            return $this->finish($state, $this->selected($state['data']));
        }

        $name = mb_substr(trim($text), 0, 200);

        if (($keyword = $this->items->keywordIn($name)) !== null) {
            return StepOutcome::wait([['text' => $this->refusalText($name, $keyword)."\n".'لو فيه قطعة تانية اكتبي اسمها، أو اكتبي «خلاص»']], 0, ['items_pending' => ['mode' => 'fallback']]);
        }

        return StepOutcome::continue([
            'selected_items' => [['line_item_id' => null, 'title' => $name, 'variant' => null, 'qty' => 1, 'price' => null, 'exchange_only' => false]],
            'items_pending' => null,
        ]);
    }

    /** @return array{line_item_id:int, title:string, variant:?string, qty:int, price:?float, exchange_only:bool} */
    private function row(OrderItem $item, int $qty): array
    {
        return [
            'line_item_id' => (int) $item->id,
            'title' => (string) $item->title,
            'variant' => $this->items->variantOf($item),
            'qty' => $qty,
            'price' => $item->price !== null ? (float) $item->price : null,
            'exchange_only' => $this->items->isDiscounted($item),
        ];
    }

    /** @param  list<array<string, mixed>>  $selected */
    private function listMessage(array $state, array $step, Order $order, array $selected, bool $withHeader): array
    {
        $chosen = array_map(fn ($s) => (int) ($s['line_item_id'] ?? 0), $selected);
        $lines = [];

        foreach ($order->items->values() as $i => $item) {
            $mark = in_array((int) $item->id, $chosen, true) ? '✅ ' : '';
            $lines[] = ($i + 1).'. '.$mark.$this->lineText($item);
        }

        $question = trim((string) ($step['text'] ?? '')) ?: self::DEFAULT_TEXT;
        $text = implode("\n", array_filter([$withHeader ? $this->header($state, $order) : null, implode("\n", $lines), '', $question], fn ($p) => $p !== null));

        return ['text' => $text, 'buttons' => $this->itemButtons($state, $order)];
    }

    /**
     * One button per item in list order (so a typed "2" is item 2), plus "كذا قطعة" for more than
     * two items. Past 12 items the rest are numbered text only (Messenger's 13 quick replies).
     *
     * @return list<array{title:string, payload:string}>
     */
    private function itemButtons(array $state, Order $order): array
    {
        $buttons = $order->items->take(self::MAX_ITEM_BUTTONS)->values()
            ->map(fn (OrderItem $item) => $this->stepButton($state, (string) $item->title, 'item:'.$item->id))->all();

        if ($order->items->count() > 2 && $order->items->count() <= self::MAX_ITEM_BUTTONS) {
            $buttons[] = $this->stepButton($state, self::MULTI_BUTTON, 'multi');
        }

        return $buttons;
    }

    private function moreMessage(array $state): array
    {
        return ['text' => self::MORE_QUESTION, 'buttons' => $this->moreButtons($state)];
    }

    /** @return list<array{title:string, payload:string}> */
    private function moreButtons(array $state): array
    {
        return [$this->stepButton($state, self::YES_BUTTON, 'more'), $this->stepButton($state, self::DONE_BUTTON, 'done')];
    }

    private function qtyMessage(array $state, ?OrderItem $item): array
    {
        if ($item === null) {
            return ['text' => self::QTY_QUESTION, 'buttons' => []];
        }

        $max = min((int) $item->qty, self::MAX_ITEM_BUTTONS + 1);
        $buttons = array_map(fn (int $n) => $this->stepButton($state, (string) $n, 'qty:'.$n), range(1, max(1, $max)));

        return ['text' => "«{$this->shortTitle($item)}» — ".self::QTY_QUESTION." (من 1 لـ {$item->qty})", 'buttons' => $buttons];
    }

    /** "لقيت أوردر #1047 باسم سارة أحمد — اتسلم يوم 12 سبتمبر" (shown only after ownership is proven). */
    private function header(array $state, Order $order): string
    {
        $number = (string) ($state['data']['order_number'] ?? ('#'.ltrim((string) ($order->shopify_order_name ?: $order->order_number ?: $order->id), '#')));
        $name = trim((string) ($order->shipping_name ?: $order->customer?->name));
        $key = (string) ($state['data']['order_status_key'] ?? '');
        $delivered = $this->items->deliveredAt($order);

        if ($delivered !== null || $key === 'delivered') {
            $when = $delivered !== null ? 'اتسلم يوم '.$this->day($delivered) : 'اتسلم';
        } else {
            $placed = CarbonImmutable::instance($order->placed_at ?? $order->created_at ?? now());
            $when = OrderStatusText::shortLabel($key).' (اتطلب يوم '.$this->day($placed).')';
        }

        return 'لقيت أوردر '.$number.($name !== '' ? " باسم {$name}" : '').' — '.$when;
    }

    private function day(CarbonImmutable $at): string
    {
        $local = $at->setTimezone(OrderStatusText::TIMEZONE);

        return $local->day.' '.self::MONTHS[$local->month - 1];
    }

    /** "فستان ليلى — أسود / M × 2 — 850 ج.م" */
    private function lineText(OrderItem $item): string
    {
        $variant = $this->items->variantOf($item);
        $price = $item->price !== null ? $this->money((float) $item->price).' ج.م' : null;

        return trim($item->title).($variant !== null ? " — {$variant}" : '').' × '.(int) $item->qty.($price !== null ? " — {$price}" : '');
    }

    private function shortTitle(OrderItem $item): string
    {
        $variant = $this->items->variantOf($item);

        return trim($item->title).($variant !== null ? " ({$variant})" : '');
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function addedText(array $rows): string
    {
        $parts = array_map(fn ($r) => $r['title'].($r['variant'] ? " ({$r['variant']})" : '').' × '.$r['qty'], $rows);

        return 'تمام ✅ ضفت: '.implode('، ', $parts);
    }

    private function refusalText(string $title, string $keyword): string
    {
        return $this->items->isAccessoryKeyword($keyword)
            ? "«{$title}» من الإكسسوارات ومش بتترجع ولا بتتبدل 🙏"
            : "«{$title}» من الأصناف اللي مش بتترجع ولا بتتبدل 🙏";
    }

    private function discountText(string $title): string
    {
        return "«{$title}» عليها خصم، فمتاحة للاستبدال بس مش استرجاع الفلوس 🌸";
    }

    private function money(float $amount): string
    {
        return number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2);
    }

    /** @return array{title:string, payload:string} */
    private function stepButton(array $state, string $title, string $value): array
    {
        return self::button($title, "step:{$state['key']}:{$state['step']}:{$value}");
    }
}
