<?php

namespace App\Bot\Flows\Steps;

use App\Bot\Catalog\ProductCards;
use App\Bot\Flows\FlowPrompter;
use App\Bot\Flows\Jobs\ProductLookupStillSearching;
use App\Bot\Flows\Returns\ExchangeProducts;
use App\Bot\Language\KeptNames;
use App\Channels\Cards\OutboundCards;
use App\Enums\AttachmentType;
use App\Inbox\OutboundService;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * `product_link` (2026-09-19; owner's picker 2026-09-22): the product she wants in exchange.
 *
 * First «أعرضلك المنتجات هنا، ولا تبعتيلي لينك المنتج أو اسمه؟»:
 *   «اعرضيلي المنتجات» → the catalog as picture cards (ProductCards), one «أبدّل بده» button each;
 *   «هبعت لينك أو اسم» → the step's own text («ابعتيلي لينك المنتج…»).
 * A store link (`/products/{handle}`, found in the catalog or in Shopify) or a typed name that
 * matches one product → that product as a card (picture, name, price) and «ده المنتج اللي تحبي
 * تبدلي بيه؟» [أيوه تمام] [لأ منتج تاني]; a name that matches several → their cards to pick from.
 * A confirmed or tapped product saves `field` = {title, handle, url, price, image, …}. A link
 * that matches nothing, or a name the catalog does not know, is asked for once more; after that
 * what she wrote is kept as `field_text` and the flow goes on. A photo instead is accepted: its
 * image ids go to `field_photo`.
 *
 * Step state lives in `data.{field}_pending` = {mode: ask|browse|link|confirm, product?: array}.
 */
final class ProductLinkStep extends BaseStep
{
    public const ASK_TEXT = 'تحبي أعرضلك المنتجات هنا، ولا تبعتيلي لينك المنتج أو اسمه؟ 🌸';

    public const BROWSE_BUTTON = 'اعرضيلي المنتجات';

    public const SEND_BUTTON = 'هبعت لينك أو اسم';

    public const BROWSE_TEXT = 'اختاري المنتج اللي تحبي تبدلي بيه 👇';

    public const PICK_BUTTON = 'أبدّل بده';

    public const CONFIRM_TEXT = 'ده المنتج اللي تحبي تبدلي بيه؟';

    public const CONFIRM_YES = 'أيوه تمام';

    public const CONFIRM_NO = 'لأ منتج تاني';

    public const CONFIRMED_TEXT = 'تمام ✅ هنبدل بـ «%s»';

    public const SEVERAL_TEXT = 'لقيت أكتر من منتج بالاسم ده، اختاري منهم 👇';

    public const NOT_FOUND_TEXT = 'مش لاقية منتج بالاسم ده 🙏 ابعتيلي لينك المنتج من الموقع، أو جربي اسم تاني';

    public const RETRY_TEXT = 'اللينك ده مش واضح، ابعتيه من صفحة المنتج على الموقع 🙏';

    /** Sent before the store is asked, so she is not left watching nothing (owner, 2026-09-21). */
    public const CHECKING_TEXT = 'ثانية واحدة 🌸 بشوف المنتج ده على الموقع';

    /** Sent by ProductLookupStillSearching when the store is slow to answer. */
    public const STILL_SEARCHING_TEXT = 'لسه بدور 🌸 ثواني كمان';

    public const DEFAULT_FIELD = 'exchange_product';

    public function __construct(FlowPrompter $prompter, private readonly ExchangeProducts $products, private readonly ProductCards $cards)
    {
        parent::__construct($prompter);
    }

    public function enter(Conversation $c, array $state, array $step): StepOutcome
    {
        $field = $this->field($step);

        // No catalog synced: nothing to browse, so straight to the link question as before.
        if ($this->cards->featured(1)->isEmpty()) {
            return StepOutcome::wait([parent::prompt($state, $step)], 0, [$field.'_pending' => ['mode' => 'link']]);
        }

        return StepOutcome::wait([$this->askMessage($state)], 0, [$field.'_pending' => ['mode' => 'ask']]);
    }

    public function prompt(array $state, array $step): array
    {
        $pending = $this->pending($state, $step);

        return match ($pending['mode']) {
            'ask' => $this->askMessage($state),
            'browse' => ['text' => self::BROWSE_TEXT, 'buttons' => [$this->stepButton($state, self::SEND_BUTTON, 'send')]],
            'confirm' => ['text' => self::CONFIRM_TEXT, 'buttons' => $this->confirmButtons($state)],
            default => parent::prompt($state, $step),
        };
    }

    public function payload(Conversation $c, array $state, array $step, string $value): ?StepOutcome
    {
        $field = $this->field($step);
        $pending = $this->pending($state, $step);

        if ($value === 'browse') {
            return $this->browse($state, $step);
        }

        if ($value === 'send') {
            return StepOutcome::wait([parent::prompt($state, $step)], null, [$field.'_pending' => ['mode' => 'link']]);
        }

        if ($value === 'yes' && $pending['mode'] === 'confirm' && is_array($pending['product'] ?? null)) {
            return $this->chosen($step, $pending['product']);
        }

        if ($value === 'no' && $pending['mode'] === 'confirm') {
            return StepOutcome::wait([$this->askMessage($state)], null, [$field.'_pending' => ['mode' => 'ask']]);
        }

        if (str_starts_with($value, 'product:') && ctype_digit(substr($value, 8))) {
            $product = Product::query()->with('variants')->find((int) substr($value, 8));

            // Tapped on its own card: she has just seen it, so no second confirmation.
            return $product !== null ? $this->chosen($step, $this->productData($product), [['text' => sprintf(self::CONFIRMED_TEXT, KeptNames::keep($product->title))]]) : null;
        }

        return null;
    }

    public function answer(Conversation $c, array $state, array $step, string $text, Collection $burst): ?StepOutcome
    {
        $field = $this->field($step);
        $photos = $this->photos($burst);
        $link = trim($text) !== '' ? $this->products->parse($text) : null;
        $product = $link !== null
            ? $this->products->resolve($link, fn () => $this->startedLookup($c))
            : null;

        if ($link !== null) {
            Cache::forget(self::lookupKey($c->id));
        }

        if ($product !== null) {
            // The link's product as a card, then «ده المنتج اللي تحبي تبدلي بيه؟» (owner, 2026-09-22).
            return $this->confirm($state, $step, $product + ['photos' => $photos]);
        }

        if ($photos !== []) {
            return StepOutcome::continue([
                $field => null,
                $field.'_photo' => $photos,
                $field.'_text' => trim($text) !== '' ? mb_substr(trim($text), 0, 500) : null,
                $field.'_pending' => null,
            ]);
        }

        // A link that matched no product: never read it as a question.
        if ($link !== null) {
            return $this->unclear($state, $step, $text);
        }

        // A typed name: the catalog decides (one → confirm card, several → cards to pick, none → ask again).
        $matches = trim($text) !== '' && mb_strlen(trim($text)) >= 3 ? $this->cards->search(trim($text), 5) : collect();

        if ($matches->count() === 1) {
            return $this->confirm($state, $step, $this->productData($matches->first()));
        }

        if ($matches->count() > 1) {
            return StepOutcome::wait([$this->cardsMessage($state, $matches, self::SEVERAL_TEXT)], null, [$field.'_pending' => ['mode' => 'browse']]);
        }

        return null;
    }

    public function unresolved(Conversation $c, array $state, array $step, string $text): StepOutcome
    {
        return $this->unclear($state, $step, $text);
    }

    /** The cache key that says a store lookup for this conversation is still running. */
    public static function lookupKey(int $conversationId): string
    {
        return 'flow:product-lookup:'.$conversationId;
    }

    private function askMessage(array $state): array
    {
        return ['text' => self::ASK_TEXT, 'buttons' => [$this->stepButton($state, self::BROWSE_BUTTON, 'browse'), $this->stepButton($state, self::SEND_BUTTON, 'send')]];
    }

    private function browse(array $state, array $step): StepOutcome
    {
        $products = $this->cards->featured();

        if ($products->isEmpty()) {
            return StepOutcome::wait([parent::prompt($state, $step)], null, [$this->field($step).'_pending' => ['mode' => 'link']]);
        }

        return StepOutcome::wait([$this->cardsMessage($state, $products, self::BROWSE_TEXT)], null, [$this->field($step).'_pending' => ['mode' => 'browse']]);
    }

    /** @param  Collection<int, Product>  $products */
    private function cardsMessage(array $state, Collection $products, string $question): array
    {
        $cards = $this->cards->cards($products);
        $cards['cards'] = array_map(fn (array $card, Product $p) => [
            'buttons' => [OutboundCards::postback(self::PICK_BUTTON, "step:{$state['key']}:{$state['step']}:product:{$p->id}"), OutboundCards::webUrl(ProductCards::VIEW_BUTTON, $this->cards->url($p))],
        ] + $card, $cards['cards'], $products->all());

        return [
            'text' => $question."\n\n".$this->cards->fallbackText($products),
            'cards' => $cards,
            'buttons' => [$this->stepButton($state, self::SEND_BUTTON, 'send')],
        ];
    }

    /** The found product as one card, then the confirmation question with its buttons. */
    private function confirm(array $state, array $step, array $product): StepOutcome
    {
        $title = KeptNames::keep((string) $product['title']);
        $price = $product['price'] !== null ? rtrim(rtrim(number_format((float) $product['price'], 2, '.', ''), '0'), '.').' جنيه' : null;
        $subtitle = implode(' · ', array_filter([$product['variant_title'] ?? null, $price]));
        $card = OutboundCards::generic([[
            'title' => $title,
            'subtitle' => $subtitle !== '' ? $subtitle : null,
            'text' => implode("\n", array_filter(['✨ '.$title, $subtitle, $product['url'] ?? null])),
            'image_url' => ProductCards::jpeg($product['image'] ?? null),
            'url' => $product['url'] ?? null,
            'buttons' => [OutboundCards::webUrl(ProductCards::VIEW_BUTTON, (string) $product['url'])],
        ]]);

        return StepOutcome::wait([
            ['text' => implode("\n", array_filter(['✨ '.$title, $subtitle])), 'cards' => $card],
            ['text' => self::CONFIRM_TEXT, 'buttons' => $this->confirmButtons($state)],
        ], null, [$this->field($step).'_pending' => ['mode' => 'confirm', 'product' => $product]]);
    }

    /** @return list<array{title:string, payload:string}> */
    private function confirmButtons(array $state): array
    {
        return [$this->stepButton($state, self::CONFIRM_YES, 'yes'), $this->stepButton($state, self::CONFIRM_NO, 'no')];
    }

    private function chosen(array $step, array $product, array $messages = []): StepOutcome
    {
        $field = $this->field($step);
        $photos = $product['photos'] ?? [];
        unset($product['photos']);

        return StepOutcome::continue([
            $field => $product,
            $field.'_text' => null,
            $field.'_photo' => $photos !== [] ? $photos : null,
            $field.'_pending' => null,
        ], $messages);
    }

    /** The same shape ExchangeProducts::resolve() returns, for a catalog product. */
    private function productData(Product $p): array
    {
        $p->loadMissing('variants');
        $price = $p->variants->whereNotNull('price')->min('price');

        return [
            'title' => (string) $p->title,
            'handle' => (string) $p->handle,
            'url' => $this->cards->url($p),
            'price' => $price !== null ? (float) $price : null,
            'image' => $p->image_url,
            'variant_title' => null,
            'variant_id' => null,
            'product_id' => (int) $p->id,
            'source' => 'catalog',
        ];
    }

    /** @return array{mode:string, product?:array} */
    private function pending(array $state, array $step): array
    {
        $p = $state['data'][$this->field($step).'_pending'] ?? null;

        return is_array($p) && is_string($p['mode'] ?? null) ? $p : ['mode' => 'link'];
    }

    /**
     * «ثانية واحدة» now, and «لسه بدور» if the store has not answered within
     * `crm.bot.product_lookup_notice_seconds`. The marker is cleared as soon as the
     * lookup returns, so the second line only ever reaches a genuinely slow lookup.
     */
    private function startedLookup(Conversation $c): void
    {
        $this->say($c, self::CHECKING_TEXT);

        $marker = (string) Str::uuid();
        $after = max(2, (int) config('crm.bot.product_lookup_notice_seconds', 6));
        Cache::put(self::lookupKey($c->id), $marker, now()->addMinutes(2));
        ProductLookupStillSearching::dispatch($c->id, $marker)->delay(now()->addSeconds($after));
    }

    /** A line sent straight away, outside the step's own outcome (sandbox-safe: the container decides). */
    private function say(Conversation $c, string $text): void
    {
        try {
            app(OutboundService::class)->sendBot($c, $text);
        } catch (Throwable $e) {
            Log::info('flow.product_link_notice_failed', ['conversation_id' => $c->id, 'error' => $e->getMessage()]);
        }
    }

    /** Asked once more, then what she wrote is kept and the flow goes on. */
    private function unclear(array $state, array $step, string $text): StepOutcome
    {
        $field = $this->field($step);
        $before = is_string($state['data'][$field.'_text'] ?? null) ? $state['data'][$field.'_text'] : '';
        $typed = implode("\n", array_values(array_unique(array_filter([trim($before), trim($text)], fn ($t) => $t !== ''))));
        $typed = $typed !== '' ? mb_substr($typed, 0, 500) : null;

        if ($state['retries'] >= 1) {
            return StepOutcome::continue([$field => null, $field.'_text' => $typed, $field.'_pending' => null]);
        }

        $isLink = $this->products->parse($text) !== null;

        return StepOutcome::wait([['text' => $isLink ? self::RETRY_TEXT : self::NOT_FOUND_TEXT]], $state['retries'] + 1, [$field.'_text' => $typed, $field.'_pending' => ['mode' => 'link']]);
    }

    private function field(array $step): string
    {
        $field = $step['field'] ?? null;

        return is_string($field) && $field !== '' ? $field : self::DEFAULT_FIELD;
    }

    /** @return array{title:string, payload:string} */
    private function stepButton(array $state, string $title, string $value): array
    {
        return self::button($title, "step:{$state['key']}:{$state['step']}:{$value}");
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
