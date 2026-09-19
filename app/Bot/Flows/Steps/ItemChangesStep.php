<?php

namespace App\Bot\Flows\Steps;

use App\Bot\Flows\FlowAnswerResolver;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowPrompter;
use App\Bot\Flows\Returns\ExchangeProducts;
use App\Enums\AttachmentType;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Collection;

/**
 * `item_changes` (the owner's cancel/edit flow, 2026-09-19): for each piece she picked in
 * the `order_items` step before it (`selected_items`), in order:
 *
 *   «{piece}» تحبي تبدليها ولا تشيليها من الأوردر؟ [أبدلها] [أشيلها]
 *     أبدلها → «ابعتيلي لينك المنتج اللي عايزاه بدلها، أو اكتبي المقاس/اللون الجديد 🌸»
 *              a store link goes through the product lookup (the `product_link` rules: a link
 *              that matches nothing is asked for once more, then kept as she wrote it); text is
 *              kept as the new size/colour; a photo is kept with the case photos.
 *     أشيلها → saved as removed.
 *
 * The result is `data.item_changes` = [{line_item_id, title, variant, qty, action: swap|remove,
 * product?, new_option?, photo?}]; the step state is `data.changes_pending` = {index, mode: action|swap}.
 */
final class ItemChangesStep extends BaseStep
{
    public const ACTION_TEXT = "«%s»\nتحبي تبدليها ولا تشيليها من الأوردر؟";

    public const SWAP_TEXT = 'ابعتيلي لينك المنتج اللي عايزاه بدلها، أو اكتبي المقاس/اللون الجديد 🌸';

    public const SWAP_BUTTON = 'أبدلها';

    public const REMOVE_BUTTON = 'أشيلها';

    private const SWAP_PATTERN = '/بدل|استبدال|تبديل|غير|تغيير|swap|change|exchange/u';

    private const REMOVE_PATTERN = '/شيل|الغي|الغاء|امسح|احذف|حذف|مش عايزاها|مش عاوزاها|remove|delete/u';

    public function __construct(
        FlowPrompter $prompter,
        private readonly ExchangeProducts $products,
        private readonly FlowAnswerResolver $resolver,
    ) {
        parent::__construct($prompter);
    }

    public function enter(Conversation $c, array $state, array $step): StepOutcome
    {
        $items = $this->items($state['data']);

        if ($items === []) {
            return StepOutcome::continue(['item_changes' => null, 'changes_pending' => null]);
        }

        $state['data']['changes_pending'] = ['index' => 0, 'mode' => 'action'];

        return StepOutcome::wait([$this->prompt($state, $step)], 0, ['item_changes' => [], 'changes_pending' => ['index' => 0, 'mode' => 'action']]);
    }

    public function prompt(array $state, array $step): array
    {
        $pending = $this->pending($state['data']);
        $item = $this->items($state['data'])[$pending['index']] ?? null;

        if ($item === null) {
            return ['text' => self::SWAP_TEXT, 'buttons' => []];
        }

        if ($pending['mode'] === 'swap') {
            return ['text' => self::SWAP_TEXT, 'buttons' => []];
        }

        return ['text' => sprintf(self::ACTION_TEXT, self::itemLabel($item)), 'buttons' => [
            self::button(self::SWAP_BUTTON, "step:{$state['key']}:{$state['step']}:swap"),
            self::button(self::REMOVE_BUTTON, "step:{$state['key']}:{$state['step']}:remove"),
        ]];
    }

    public function answer(Conversation $c, array $state, array $step, string $text, Collection $burst): ?StepOutcome
    {
        $pending = $this->pending($state['data']);

        if ($pending['mode'] === 'swap') {
            return $this->swapAnswer($state, $step, $text, $burst);
        }

        $clean = $this->resolver->clean($text);

        return match (true) {
            $clean === '' => null,
            preg_match(self::REMOVE_PATTERN, $clean) === 1 => $this->decide($state, $step, 'remove'),
            preg_match(self::SWAP_PATTERN, $clean) === 1 => $this->decide($state, $step, 'swap'),
            default => null,
        };
    }

    public function payload(Conversation $c, array $state, array $step, string $value): ?StepOutcome
    {
        if ($this->pending($state['data'])['mode'] !== 'action' || ! in_array($value, ['swap', 'remove'], true)) {
            return null;
        }

        return $this->decide($state, $step, $value);
    }

    public function unresolved(Conversation $c, array $state, array $step, string $text): StepOutcome
    {
        return StepOutcome::retry();
    }

    /** «أبدلها» asks what for; «أشيلها» saves the piece as removed and moves on. */
    private function decide(array $state, array $step, string $action): StepOutcome
    {
        $pending = $this->pending($state['data']);

        if ($action === 'swap') {
            return StepOutcome::wait([['text' => self::SWAP_TEXT]], 0, ['changes_pending' => ['mode' => 'swap', 'retried' => false] + $pending]);
        }

        return $this->record($state, $step, ['action' => 'remove']);
    }

    private function swapAnswer(array $state, array $step, string $text, Collection $burst): ?StepOutcome
    {
        $photos = $this->photos($burst);
        $link = trim($text) !== '' ? $this->products->parse($text) : null;
        $product = $link !== null ? $this->products->resolve($link) : null;
        $typed = trim($text) !== '' ? mb_substr(trim($text), 0, 300) : null;

        if ($product !== null) {
            return $this->record($state, $step, ['action' => 'swap', 'product' => $product] + ($photos !== [] ? ['photo' => $photos] : []));
        }

        // A link that matched nothing: once more, then kept as she wrote it.
        if ($link !== null && $photos === [] && ! ($this->pending($state['data'])['retried'] ?? false)) {
            return StepOutcome::wait([['text' => ProductLinkStep::RETRY_TEXT]], 0, ['changes_pending' => ['retried' => true] + $this->pending($state['data'])]);
        }

        if ($photos !== [] || ($typed !== null && FlowEngine::meaningful($typed))) {
            return $this->record($state, $step, array_filter(['action' => 'swap', 'new_option' => $typed, 'photo' => $photos !== [] ? $photos : null], fn ($v) => $v !== null));
        }

        return null;
    }

    /** Saves the change of the current piece, then asks about the next one or goes on. */
    private function record(array $state, array $step, array $change): StepOutcome
    {
        $items = $this->items($state['data']);
        $pending = $this->pending($state['data']);
        $item = $items[$pending['index']] ?? null;
        $changes = array_values(array_filter((array) ($state['data']['item_changes'] ?? []), 'is_array'));

        if ($item !== null) {
            $changes[] = [
                'line_item_id' => $item['line_item_id'] ?? null,
                'title' => (string) ($item['title'] ?? ''),
                'variant' => $item['variant'] ?? null,
                'qty' => max(1, (int) ($item['qty'] ?? 1)),
            ] + $change;
        }

        $next = $pending['index'] + 1;

        if ($next >= count($items)) {
            return StepOutcome::continue(['item_changes' => $changes, 'changes_pending' => null]);
        }

        $state['data']['item_changes'] = $changes;
        $state['data']['changes_pending'] = ['index' => $next, 'mode' => 'action'];

        return StepOutcome::wait([$this->prompt($state, $step)], 0, ['item_changes' => $changes, 'changes_pending' => ['index' => $next, 'mode' => 'action']]);
    }

    /** "فستان ليلى (أسود / M)" (× qty when more than one). */
    public static function itemLabel(array $item): string
    {
        $variant = is_scalar($item['variant'] ?? null) && trim((string) $item['variant']) !== '' ? ' ('.trim((string) $item['variant']).')' : '';
        $qty = (int) ($item['qty'] ?? 1);

        return trim((string) ($item['title'] ?? '')).$variant.($qty > 1 ? ' × '.$qty : '');
    }

    /** @return list<array<string, mixed>> */
    private function items(array $data): array
    {
        return array_values(array_filter((array) ($data['selected_items'] ?? []), fn ($i) => is_array($i) && filled($i['title'] ?? null)));
    }

    /** @return array{index:int, mode:string, retried?:bool} */
    private function pending(array $data): array
    {
        $p = $data['changes_pending'] ?? null;

        return is_array($p) ? ['index' => max(0, (int) ($p['index'] ?? 0)), 'mode' => ($p['mode'] ?? 'action') === 'swap' ? 'swap' : 'action'] + $p : ['index' => 0, 'mode' => 'action'];
    }

    /** @return list<int|string> */
    private function photos(Collection $burst): array
    {
        $ids = [];
        $legacy = false;

        foreach ($burst as $m) {
            /** @var Message $m */
            if ($m->exists) {
                array_push($ids, ...$m->mediaAttachments()->where('type', AttachmentType::Image->value)->pluck('id')->map(fn ($id) => (int) $id)->all());
            }

            $legacy = $legacy || collect((array) $m->attachments)->contains(fn ($a) => is_array($a) && ($a['type'] ?? null) === 'image');
        }

        return $ids !== [] ? $ids : ($legacy ? ['legacy'] : []);
    }
}
