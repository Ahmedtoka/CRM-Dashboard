<?php

namespace App\Bot\Flows\Steps;

use App\Bot\Catalog\ProductCards;
use App\Bot\Flow\Orders\OrderStatusText;
use App\Bot\Flows\FlowAnswerResolver;
use App\Bot\Flows\FlowPrompter;
use App\Bot\Flows\OwnerFlowsUpgrade;
use App\Bot\Flows\Returns\ItemSelection;
use App\Bot\Flows\Returns\ReturnItems;
use App\Bot\Language\KeptNames;
use App\Channels\Cards\OutboundCards;
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
 * With `data.request_kind` (the owner's 2026-09-19 flow asks "ترجعي ولا تبدلي؟" first):
 * `return` refuses a discounted item (it can only be exchanged) and offers to
 * turn the request into an exchange for it ("أبدلها بدل كده" → request_kind
 * `exchange`, the item is added, and the step's branches take the flow to the
 * exchange steps); `exchange` takes discounted items without a note. The
 * order header is not repeated then (the greeting already named the order).
 *
 * With `return_rules: false` (the owner's cancel/edit flow, 2026-09-19) it is a plain picker:
 * no return rules at all (no keyword refusals, no 14 days, no discount notes) and no order
 * header — she is only saying which pieces she wants to change.
 *
 * Step state lives in `data.items_pending` = {mode: pick|more|qty|fallback, queue?: ids, qty_for?: id, discounted?: ids}.
 */
final class OrderItemsStep extends BaseStep
{
    public const DEFAULT_TEXT = 'اختاري القطعة اللي عايزة ترجعيها أو تبدليها 👇';

    public const FALLBACK_TEXT = 'اكتبي اسم القطعة اللي عايزة ترجعيها أو تبدليها 🌸';

    /** `return_rules: false` (cancel/edit): what is asked instead. */
    public const PLAIN_TEXT = 'اختاري القطعة اللي عايزة تعدلي فيها 👇';

    public const PLAIN_FALLBACK_TEXT = 'اكتبي اسم القطعة اللي عايزة تعدلي فيها 🌸';

    public const MULTI_TEXT = 'اكتبي أرقام القطع اللي عايزاها، مثلًا: 1 و 3';

    public const MORE_QUESTION = 'تحبي تضيفي قطعة تانية؟';

    public const OTHER_QUESTION = 'تحبي تختاري قطعة تانية؟';

    public const QTY_QUESTION = 'كام قطعة؟';

    public const ALREADY_TEXT = '«%s» موجودة في اختياراتك خلاص 🌸';

    public const NOTHING_TEXT = 'تمام 🌸 لو احتجتي أي حاجة تانية أنا موجودة';

    public const MULTI_BUTTON = 'كذا قطعة';

    /** The one button on a piece's picture card (owner's wording, 2026-09-26). */
    public const PICK_BUTTON = 'اختاري القطعة دي';

    public const PICK_BUTTON_RETURN = 'رجّع القطعة دي';

    public const PICK_BUTTON_EXCHANGE = 'بدّل القطعة دي';

    public const PICK_BUTTON_EDIT = 'عدّل القطعة دي';

    /** Under the pictures (owner, 2026-09-26): «اختاري من الصور 👆 أو اكتبي رقمها». */
    public const PICTURES_HINT = 'اختاري من الصور 👆 أو اكتبي رقم القطعة';

    /** One tap for the whole order (owner, 2026-09-21): «أرجع كله» / «أبدل كله». */
    public const ALL_BUTTON_RETURN = 'أرجع كله';

    public const ALL_BUTTON_EXCHANGE = 'أبدل كله';

    /** After the first pick (owner, 2026-09-22): the rest of the order in one tap. */
    public const REST_BUTTON_RETURN = 'أرجع الباقي كله';

    public const REST_BUTTON_EXCHANGE = 'أبدل الباقي كله';

    public const YES_BUTTON = 'أيوه';

    public const DONE_BUTTON = 'لأ كده تمام';

    public const OTHER_BUTTON = 'قطعة تانية';

    public const HUMAN_BUTTON = 'كلم موظف';

    public const SWITCH_BUTTON = 'أبدلها بدل كده';

    public const SWITCH_QUESTION = 'تحبي تبدليها بدل ما ترجعيها؟';

    public const SWITCHED_TEXT = 'تمام 🌸 هنكمل الطلب استبدال';

    public const EXCHANGE_TITLE = 'استبدال';

    /** Typed "switch to exchange" after a discounted item was refused for a return. */
    private const SWITCH_PATTERN = '/بدل|استبدال|تبديل|exchange/u';

    /** Messenger: 13 quick replies. One slot stays for "كذا قطعة". */
    private const MAX_ITEM_BUTTONS = 12;

    /** Replies that end the picking ("that's all"). */
    private const DONE_WORDS = ['لا', 'لاء', 'لا كده تمام', 'لا كدا تمام', 'كده تمام', 'كدا تمام', 'خلاص', 'بس كده', 'بس كدا', 'بس', 'لا شكرا', 'مفيش', 'no', 'تمام كده'];

    private const MONTHS = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

    /** The same months in English, for an English conversation (design 2026-09-21 §1). */
    private const MONTHS_EN = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    /** `return_rules: false` on the step being handled (set by every entry point). */
    private bool $plain = false;

    /** `optional: true`: she may continue without picking (the complaint's pieces, 2026-09-22). */
    private bool $optional = false;

    /** `pick_button`: the card button's own words («الشكوى عن دي»). */
    private ?string $pickButton = null;

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
        $this->configure($step);
        $order = $this->order($state['data']);

        // An optional picker with nothing to show (no synced pieces) is simply passed.
        if ($this->optional && ($order === null || $order->items->isEmpty())) {
            return StepOutcome::continue(['selected_items' => [], 'items_pending' => null]);
        }

        if ($order === null || $order->items->isEmpty()) {
            return StepOutcome::wait([['text' => $this->fallbackText()]], 0, ['items_pending' => ['mode' => 'fallback'], 'selected_items' => null]);
        }

        return StepOutcome::wait($this->listMessages($state, $step, $order, [], $this->kind($state) === null && ! $this->plain), 0, ['items_pending' => ['mode' => 'pick'], 'selected_items' => null]);
    }

    /** `return_rules: false`: a plain picker without the return rules (cancel/edit). */
    public static function isPlain(array $step): bool
    {
        return ($step['return_rules'] ?? true) === false;
    }

    /** Reads the step's switches once per entry point. */
    private function configure(array $step): void
    {
        $this->plain = self::isPlain($step);
        $this->optional = ($step['optional'] ?? false) === true;
        $this->pickButton = is_string($step['pick_button'] ?? null) && trim($step['pick_button']) !== '' ? trim($step['pick_button']) : null;
    }

    private function fallbackText(): string
    {
        return $this->plain ? self::PLAIN_FALLBACK_TEXT : self::FALLBACK_TEXT;
    }

    public function prompt(array $state, array $step): array
    {
        $this->configure($step);
        $pending = $this->pending($state['data']);
        $order = $this->order($state['data']);

        if ($pending['mode'] === 'fallback' || $order === null || $order->items->isEmpty()) {
            return ['text' => $this->fallbackText(), 'buttons' => []];
        }

        return match ($pending['mode']) {
            'more' => ($pending['discounted'] ?? []) !== []
                ? ['text' => self::SWITCH_QUESTION, 'buttons' => [$this->stepButton($state, self::SWITCH_BUTTON, 'switch'), $this->stepButton($state, self::DONE_BUTTON, 'done')]]
                : $this->moreMessage($state),
            'qty' => $this->qtyMessage($state, $order->items->firstWhere('id', (int) ($pending['qty_for'] ?? 0))),
            default => $this->listMessage($state, $step, $order, $this->selected($state['data']), false),
        };
    }

    public function answer(Conversation $c, array $state, array $step, string $text, Collection $burst): ?StepOutcome
    {
        $this->configure($step);
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
            // "تحبي تبدليها بدل ما ترجعيها؟" → "أيوه" / "ابدلها".
            if (($pending['discounted'] ?? []) !== [] && (preg_match(self::SWITCH_PATTERN, $this->resolver->clean($text)) === 1 || $this->resolver->yesNo($text) === 'yes')) {
                return $this->switchToExchange($state, $order);
            }

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
            return $this->pick($state, $order, $this->remaining($state, $order)->pluck('id')->map(fn ($id) => (int) $id)->all());
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
        $this->configure($step);
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

        if ($value === 'switch') {
            return $this->switchToExchange($state, $order);
        }

        if ($value === 'multi') {
            return StepOutcome::wait([['text' => self::MULTI_TEXT]], 0, ['items_pending' => ['mode' => 'pick']]);
        }

        // «أرجع كله» / «أرجع الباقي كله» / the last card: every piece she has not picked yet.
        if ($value === 'all') {
            return $this->pick($state, $order, $this->remaining($state, $order)->pluck('id')->map(fn ($id) => (int) $id)->all());
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
        $clean = $this->resolver->clean($text);

        return in_array($clean, array_map(fn ($w) => $this->resolver->clean($w), self::DONE_WORDS), true)
            || $this->resolver->yesNo($text) === 'no'
            // «No, that is all», «لأ كده تمام»: a whole sentence, not just the bare word.
            || $this->selection->isRefusal($clean);
    }

    private function showList(array $state, array $step, Order $order): StepOutcome
    {
        return StepOutcome::wait($this->listMessages($state, $step, $order, $this->selected($state['data']), false), 0, ['items_pending' => ['mode' => 'pick']]);
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
        $discounted = [];

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

            if (! $this->plain && ($keyword = $this->items->nonReturnableKeyword($item)) !== null) {
                $messages[] = ['text' => $this->refusalText($title, $keyword)];
                $refused = true;

                continue;
            }

            if (! $this->plain && $this->items->windowClosed($order)) {
                $lateText = "«{$title}» عدّى على استلامها أكتر من ".ReturnItems::RETURN_DAYS.' يوم، والمرتجع والاستبدال عندنا خلال '.ReturnItems::RETURN_DAYS.' يوم من الاستلام بس 🙏';
                $late = true;

                continue;
            }

            // A return: a discounted piece can only be exchanged (she is offered to switch).
            if ($this->kind($state) === 'return' && $this->items->isDiscounted($item)) {
                $messages[] = ['text' => $this->discountReturnText($title)];
                $discounted[] = $id;

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

            if ($row['exchange_only'] && $this->kind($state) === null) {
                $messages[] = ['text' => $this->discountText($title)];
            }
        }

        return $this->afterPicking($state, $order, $selected, $added, $messages, $refused, $late ? $lateText : null, $discounted);
    }

    private function withQuantity(array $state, Order $order, OrderItem $item, int $qty): StepOutcome
    {
        $selected = $this->selected($state['data']);
        $row = $this->row($item, $qty);
        $selected[] = $row;
        $messages = $row['exchange_only'] && $this->kind($state) === null ? [['text' => $this->discountText($this->shortTitle($item))]] : [];
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
    private function afterPicking(array $state, Order $order, array $selected, array $added, array $messages, bool $refused, ?string $lateText, array $discounted = []): StepOutcome
    {
        $data = ['selected_items' => $selected];
        $returning = $this->kind($state) === 'return';
        // Items she could still pick: not picked yet, not refused by the keyword list, and (for a return) not discounted.
        $left = $order->items->reject(fn (OrderItem $i) => collect($selected)->contains(fn ($s) => (int) ($s['line_item_id'] ?? 0) === (int) $i->id)
            || (! $this->plain && $this->items->nonReturnableKeyword($i) !== null)
            || ($returning && $this->items->isDiscounted($i)));

        // The window is the order's, so every other item is past it too: a person, or stop here.
        if ($lateText !== null) {
            $buttons = [self::button(self::HUMAN_BUTTON, 'handover'), $this->stepButton($state, self::DONE_BUTTON, 'done')];

            if ($added !== []) {
                $messages[] = ['text' => $this->addedText($added)];
            }

            $messages[] = ['text' => $lateText."\nتحبي أحوّلك لحد من الفريق؟", 'buttons' => $buttons];

            return StepOutcome::wait($messages, 0, $data + ['items_pending' => ['mode' => 'more']]);
        }

        // A discounted piece in a return: switch to an exchange, pick another, or stop here.
        if ($discounted !== []) {
            $previous = array_map('intval', (array) ($this->pending($state['data'])['discounted'] ?? []));
            $buttons = [$this->stepButton($state, self::SWITCH_BUTTON, 'switch')];

            if ($left->isNotEmpty()) {
                $buttons[] = $this->stepButton($state, self::OTHER_BUTTON, 'more');
            }

            $buttons[] = $this->stepButton($state, self::DONE_BUTTON, 'done');
            $messages[] = ['text' => ($added !== [] ? $this->addedText($added)."\n" : '').self::SWITCH_QUESTION, 'buttons' => $buttons];

            return StepOutcome::wait($messages, 0, $data + ['items_pending' => ['mode' => 'more', 'discounted' => array_values(array_unique([...$previous, ...$discounted]))]]);
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

    /**
     * "أبدلها بدل كده": the request becomes an exchange and the discounted pieces she was refused
     * are picked again as exchange items (a quantity question still applies). The step's branches
     * then take the flow to the exchange steps.
     */
    private function switchToExchange(array $state, ?Order $order): ?StepOutcome
    {
        $ids = array_map('intval', (array) ($this->pending($state['data'])['discounted'] ?? []));

        if ($order === null || $ids === []) {
            return null;
        }

        $switch = ['request_kind' => 'exchange', 'request_kind_title' => self::EXCHANGE_TITLE];
        $state['data'] = $switch + $state['data'];
        $state['data']['items_pending'] = ['mode' => 'pick'];

        return $this->pick($state, $order, $ids, [['text' => self::SWITCHED_TEXT]])->withData($switch);
    }

    /** `return` / `exchange` when the flow already asked which one she wants, else null. */
    private function kind(array $state): ?string
    {
        $kind = $state['data']['request_kind'] ?? null;

        return in_array($kind, ['return', 'exchange'], true) ? $kind : null;
    }

    /** @param  list<array<string, mixed>>  $selected */
    private function finish(array $state, array $selected, array $messages = []): StepOutcome
    {
        // `optional: true` (the complaint's pieces, 2026-09-22): nothing picked is an answer too.
        if ($selected === [] && $this->optional) {
            return StepOutcome::continue(['selected_items' => [], 'items_pending' => null], $messages);
        }

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

        if (! $this->plain && ($keyword = $this->items->keywordIn($name)) !== null) {
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
            'exchange_only' => ! $this->plain && $this->items->isDiscounted($item),
        ];
    }

    /**
     * The order's pieces as picture cards (owner, 2026-09-22): each card is the piece's own photo,
     * its name, «أسود / M × 1 — 850 ج.م» and one button that picks it; the question follows as its
     * own message, so its quick replies («أرجع كله», «كذا قطعة», the names) stay under the newest
     * message and a typed "2" still means piece 2. With no photos at all, or past 10 pieces, the numbered
     * text list goes out as before.
     *
     * @param  list<array<string, mixed>>  $selected
     * @return list<array<string, mixed>>
     */
    private function listMessages(array $state, array $step, Order $order, array $selected, bool $withHeader): array
    {
        $list = $this->listMessage($state, $step, $order, $selected, $withHeader);
        // The pieces still open, each with its number in the order (a typed "3" is still piece 3).
        $numbers = $order->items->values()->pluck('id')->flip();
        $items = $selected === [] ? $order->items->values() : $this->remaining($state, $order);
        $pictures = $items->map(fn (OrderItem $i) => ProductCards::jpeg($i->image_url ?: $i->variant?->image_url ?: $i->variant?->product?->image_url));

        if ($items->count() > OutboundCards::MAX_CARDS || $pictures->filter()->isEmpty()) {
            return [$list];
        }

        $pick = match (true) {
            $this->pickButton !== null => $this->pickButton,
            $this->plain => self::PICK_BUTTON_EDIT,
            $this->kind($state) === 'exchange' => self::PICK_BUTTON_EXCHANGE,
            $this->kind($state) === 'return' => self::PICK_BUTTON_RETURN,
            default => self::PICK_BUTTON,
        };

        $cards = $items->map(fn (OrderItem $item, int $i) => [
            'title' => ($numbers[$item->id] + 1).'. '.KeptNames::keep(trim((string) $item->title)),
            'subtitle' => trim(($this->items->variantOf($item) ?? '').' × '.(int) $item->qty.($item->price !== null ? ' — '.$this->money((float) $item->price).' ج.م' : ''), ' —'),
            'text' => ($numbers[$item->id] + 1).'. '.$this->lineText($item),
            'image_url' => $pictures[$i],
            'buttons' => [OutboundCards::postback($pick, "step:{$state['key']}:{$state['step']}:item:{$item->id}")],
        ])->all();

        $question = trim($this->prompter->renderText((string) ($step['text'] ?? ''), $state['data'] ?? [])) ?: ($this->plain ? self::PLAIN_TEXT : self::DEFAULT_TEXT);
        $cards = OutboundCards::generic($cards);
        // WhatsApp's carousel carries a body of its own: the question sits above the pictures there.
        $cards['label'] = $question;
        $header = $withHeader ? $this->header($state, $order) : null;

        // Under the pictures (owner, 2026-09-26): the question once more with «اختاري من الصور 👆 أو
        // اكتبي رقم القطعة», and only the whole-order button — every piece already has its own
        // button on its card, so the names are not repeated as buttons.
        $hint = rtrim(preg_replace('/\s*👇\s*$/u', '', $question) ?? $question).' — '.self::PICTURES_HINT;

        return [
            // The body is the numbered list: what a channel without cards shows instead.
            ['text' => $list['text'], 'cards' => $cards],
            ['text' => trim(($header !== null ? $header."\n" : '').$hint), 'buttons' => $this->wholeOrderButtons($state, $order, $selected !== [])],
        ];
    }

    /**
     * The buttons under the pictures: «أرجع كله» / «أبدل كله» (after a pick «…الباقي كله») when more than
     * one piece is still open, «كل الأوردر» on the optional picker; nothing on the edit picker.
     *
     * @return list<array{title:string, payload:string}>
     */
    private function wholeOrderButtons(array $state, Order $order, bool $openOnly): array
    {
        $buttons = [];
        $items = $openOnly ? $this->remaining($state, $order) : $order->items->values();

        if (! $this->plain && $items->count() > 1) {
            $exchange = $this->kind($state) === 'exchange';
            $buttons[] = $this->stepButton($state, $openOnly ? ($exchange ? self::REST_BUTTON_EXCHANGE : self::REST_BUTTON_RETURN) : ($exchange ? self::ALL_BUTTON_EXCHANGE : self::ALL_BUTTON_RETURN), 'all');
        }

        if ($this->optional && ! $openOnly) {
            $buttons[] = $this->stepButton($state, OwnerFlowsUpgrade::COMPLAINT_SKIP_BUTTON, 'done');
        }

        return $buttons;
    }

    /** @param  list<array<string, mixed>>  $selected */
    private function listMessage(array $state, array $step, Order $order, array $selected, bool $withHeader): array
    {
        $lines = [];

        foreach ($order->items->values() as $i => $item) {
            // After a pick only the open pieces are listed, with their original numbers (2026-09-22).
            if ($selected !== [] && ! $this->remaining($state, $order)->contains('id', $item->id)) {
                continue;
            }

            $lines[] = ($i + 1).'. '.$this->lineText($item);
        }

        $question = trim($this->prompter->renderText((string) ($step['text'] ?? ''), $state['data'] ?? [])) ?: ($this->plain ? self::PLAIN_TEXT : self::DEFAULT_TEXT);
        $text = implode("\n", array_filter([$withHeader ? $this->header($state, $order) : null, implode("\n", $lines), '', $question], fn ($p) => $p !== null));

        return ['text' => $text, 'buttons' => $this->itemButtons($state, $order, $selected !== [])];
    }

    /**
     * One button per item in list order (so a typed "2" is item 2), plus "كذا قطعة" for more than
     * two items. Past 12 items the rest are numbered text only (Messenger's 13 quick replies).
     *
     * @return list<array{title:string, payload:string}>
     */
    private function itemButtons(array $state, Order $order, bool $openOnly = false): array
    {
        $items = $openOnly ? $this->remaining($state, $order) : $order->items->values();
        $buttons = $items->take(self::MAX_ITEM_BUTTONS)->values()
            // The piece's own name goes out as she reads it on the invoice, in either language (§4).
            ->map(fn (OrderItem $item) => $this->stepButton($state, KeptNames::keep((string) $item->title), 'item:'.$item->id))->all();

        // «أرجع كله» / «أبدل كله» (after a pick: «أرجع الباقي كله»): the open pieces in one tap.
        // Not on the edit picker, where "return everything" would be the wrong thing to offer.
        if (! $this->plain && $items->count() > 1) {
            $exchange = ($state['data']['request_kind'] ?? null) === 'exchange';
            $all = $openOnly ? ($exchange ? self::REST_BUTTON_EXCHANGE : self::REST_BUTTON_RETURN) : ($exchange ? self::ALL_BUTTON_EXCHANGE : self::ALL_BUTTON_RETURN);
            $buttons[] = $this->stepButton($state, $all, 'all');
        }

        if ($items->count() > 2 && $items->count() <= self::MAX_ITEM_BUTTONS) {
            $buttons[] = $this->stepButton($state, self::MULTI_BUTTON, 'multi');
        }

        if ($this->optional && ! $openOnly) {
            $buttons[] = $this->stepButton($state, OwnerFlowsUpgrade::COMPLAINT_SKIP_BUTTON, 'done');
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
        $buttons = [$this->stepButton($state, self::YES_BUTTON, 'more')];
        $order = $this->order($state['data']);

        // 2026-09-22: the rest of the order in one tap, when more than one piece is still open.
        if ($order !== null && $this->remaining($state, $order)->count() > 1) {
            $buttons[] = $this->stepButton($state, $this->kind($state) === 'exchange' ? self::REST_BUTTON_EXCHANGE : self::REST_BUTTON_RETURN, 'all');
        }

        $buttons[] = $this->stepButton($state, self::DONE_BUTTON, 'done');

        return $buttons;
    }

    /**
     * The pieces she can still pick (2026-09-22: the list after «أيوه» no longer repeats the ones
     * she chose): not picked yet, not refused by the keyword list, and not discounted in a return.
     *
     * @return Collection<int, OrderItem>
     */
    private function remaining(array $state, Order $order): Collection
    {
        $selected = $this->selected($state['data']);
        $returning = $this->kind($state) === 'return';

        return $order->items->reject(fn (OrderItem $i) => collect($selected)->contains(fn ($s) => (int) ($s['line_item_id'] ?? 0) === (int) $i->id)
            || (! $this->plain && $this->items->nonReturnableKeyword($i) !== null)
            || ($returning && $this->items->isDiscounted($i)))->values();
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

        return 'لقيت أوردر '.$number.($name !== '' ? ' باسم '.KeptNames::keep($name) : '').' — '.$when;
    }

    private function day(CarbonImmutable $at): string
    {
        $local = $at->setTimezone(OrderStatusText::TIMEZONE);

        // The month has a ready English name of its own, so a date never costs a model
        // call and «اتسلم يوم 12 سبتمبر» reads «12 September» in an English chat.
        return $local->day.' '.KeptNames::swap(self::MONTHS[$local->month - 1], self::MONTHS_EN[$local->month - 1]);
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

        return KeptNames::keep(trim($item->title).($variant !== null ? " ({$variant})" : ''));
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

    private function discountReturnText(string $title): string
    {
        return "«{$title}» عليها خصم، فمينفعش ترجع بس ممكن تتبدل 🌸";
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
