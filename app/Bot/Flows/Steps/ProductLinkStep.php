<?php

namespace App\Bot\Flows\Steps;

use App\Bot\Flows\FlowPrompter;
use App\Bot\Flows\Returns\ExchangeProducts;
use App\Enums\AttachmentType;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Collection;

/**
 * `product_link` (2026-09-19): the store link of the product she wants in
 * exchange. A link to `/products/{handle}` found in the catalog (or in Shopify)
 * saves `field` = {title, handle, url, price, image, variant_title, …}. A link
 * that matches nothing, or text without a link, is asked for once more
 * ("اللينك ده مش واضح…"); after that what she wrote is kept as `field_text`
 * and the flow goes on. A photo or screenshot instead of a link is accepted:
 * its image ids go to `field_photo` (so the case lists it with the photos).
 */
final class ProductLinkStep extends BaseStep
{
    public const RETRY_TEXT = 'اللينك ده مش واضح، ابعتيه من صفحة المنتج على الموقع 🙏';

    public const DEFAULT_FIELD = 'exchange_product';

    public function __construct(FlowPrompter $prompter, private readonly ExchangeProducts $products)
    {
        parent::__construct($prompter);
    }

    public function answer(Conversation $c, array $state, array $step, string $text, Collection $burst): ?StepOutcome
    {
        $field = $this->field($step);
        $photos = $this->photos($burst);
        $link = trim($text) !== '' ? $this->products->parse($text) : null;
        $product = $link !== null ? $this->products->resolve($link) : null;

        if ($product !== null) {
            return StepOutcome::continue([
                $field => $product,
                $field.'_text' => null,
                $field.'_photo' => $photos !== [] ? $photos : null,
            ]);
        }

        if ($photos !== []) {
            return StepOutcome::continue([
                $field => null,
                $field.'_photo' => $photos,
                $field.'_text' => trim($text) !== '' ? mb_substr(trim($text), 0, 500) : null,
            ]);
        }

        // A link that matched no product: never read it as a question.
        return $link !== null ? $this->unclear($state, $step, $text) : null;
    }

    public function unresolved(Conversation $c, array $state, array $step, string $text): StepOutcome
    {
        return $this->unclear($state, $step, $text);
    }

    /** Asked once more, then what she wrote is kept and the flow goes on. */
    private function unclear(array $state, array $step, string $text): StepOutcome
    {
        $field = $this->field($step);
        $before = is_string($state['data'][$field.'_text'] ?? null) ? $state['data'][$field.'_text'] : '';
        $typed = implode("\n", array_values(array_unique(array_filter([trim($before), trim($text)], fn ($t) => $t !== ''))));
        $typed = $typed !== '' ? mb_substr($typed, 0, 500) : null;

        if ($state['retries'] >= 1) {
            return StepOutcome::continue([$field => null, $field.'_text' => $typed]);
        }

        return StepOutcome::wait([['text' => self::RETRY_TEXT]], $state['retries'] + 1, [$field.'_text' => $typed]);
    }

    private function field(array $step): string
    {
        $field = $step['field'] ?? null;

        return is_string($field) && $field !== '' ? $field : self::DEFAULT_FIELD;
    }

    /** @return list<int|string> image attachment ids of the burst; `['legacy']` for an old-style image only */
    private function photos(Collection $burst): array
    {
        $ids = [];
        $legacy = false;

        foreach ($burst as $m) {
            /** @var Message $m */
            array_push($ids, ...$m->mediaAttachments()->where('type', AttachmentType::Image->value)->pluck('id')->map(fn ($id) => (int) $id)->all());
            $legacy = $legacy || collect((array) $m->attachments)->contains(fn ($a) => is_array($a) && ($a['type'] ?? null) === 'image');
        }

        return $ids !== [] ? $ids : ($legacy ? ['legacy'] : []);
    }
}
