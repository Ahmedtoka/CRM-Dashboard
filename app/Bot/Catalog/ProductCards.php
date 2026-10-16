<?php

namespace App\Bot\Catalog;

use App\Bot\CatalogSearch;
use App\Bot\Grounding\VariantOptions;
use App\Channels\Cards\OutboundCards;
use App\Models\BotSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;

/**
 * Products as picture cards (owner request 2026-09-22): instead of a numbered list she gets a
 * carousel — the Shopify picture, the name, the price and what is in stock — with «شوفي المنتج»
 * (the product page) and «التفاصيل» (a `product:<id>` postback answered by detail()).
 * Messenger and Instagram draw it as a generic template, WhatsApp as a media carousel, the
 * test chat as cards; the message body keeps a plain list for anything that cannot draw cards.
 */
class ProductCards
{
    public const PAYLOAD_PREFIX = 'product';

    public const VIEW_BUTTON = 'شوفي المنتج';

    public const DETAILS_BUTTON = 'التفاصيل والمقاسات';

    public const WHATSAPP_LABEL = 'الموديلات';

    public function __construct(private readonly CatalogSearch $search) {}

    /** @return Collection<int, Product> the active products a message is about */
    public function search(string $query, int $limit = OutboundCards::MAX_CARDS): Collection
    {
        return $this->search->productsFor($query, $limit)->filter(fn (Product $p) => $p->variants->isNotEmpty())->values();
    }

    /** @return Collection<int, Product> in-stock active products, newest in the store first */
    public function featured(int $limit = OutboundCards::MAX_CARDS, ?string $type = null): Collection
    {
        return Product::query()
            ->with(['variants' => fn ($v) => $v->orderBy('id')])
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))
            ->when($type !== null, fn ($q) => $q->where('product_type', $type))
            ->whereHas('variants', fn ($v) => $v->where('inventory_quantity', '>', 0))
            ->orderByDesc('shopify_updated_at')
            ->limit($limit)
            ->get();
    }

    /** @return list<string> the store's product types that have something in stock, biggest first */
    public function types(int $limit = 8): array
    {
        return Product::query()
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))
            ->whereNotNull('product_type')->where('product_type', '!=', '')
            ->whereHas('variants', fn ($v) => $v->where('inventory_quantity', '>', 0))
            ->selectRaw('product_type, count(*) as n')->groupBy('product_type')->orderByDesc('n')
            ->limit($limit)->pluck('product_type')->all();
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array{type:'generic', label:string, cards:list<array<string, mixed>>}|null
     */
    public function cards(Collection $products): ?array
    {
        if ($products->isEmpty()) {
            return null;
        }

        $cards = OutboundCards::generic($products->map(fn (Product $p) => [
            'title' => (string) $p->title,
            'subtitle' => $this->subtitle($p),
            'text' => $this->line($p),
            'image_url' => $this->image($p),
            'url' => $this->url($p),
            'buttons' => [
                OutboundCards::webUrl(self::VIEW_BUTTON, $this->url($p)),
                OutboundCards::postback(self::DETAILS_BUTTON, self::PAYLOAD_PREFIX.':'.$p->id),
            ],
        ])->all());

        return ['type' => 'generic', 'label' => self::WHATSAPP_LABEL, 'cards' => $cards['cards']];
    }

    /**
     * One variant a moderator picked in the inbox («ابعت منتج», 2026-10-17), as the same picture
     * card: that colour/size in the title, its picture and price, and the product page opened on it.
     * No «التفاصيل» postback: that button is answered by the bot, and a staff-sent card belongs to
     * a thread the bot has handed over.
     *
     * @return array{cards: array{type:'generic', label:string, cards:list<array<string, mixed>>}, text: string}
     */
    public function variantCard(ProductVariant $v): array
    {
        $p = $v->product;
        $p->loadMissing('variants');
        $isDefault = $v->title === null || in_array($v->title, ['', 'Default', 'Default Title'], true);
        $title = $isDefault ? (string) $p->title : $p->title.' — '.$v->title;
        $price = (float) $v->price > 0 ? rtrim(rtrim(number_format((float) $v->price, 2, '.', ''), '0'), '.').' جنيه' : '';
        $subtitle = implode(' · ', array_filter([$price, self::variantInStock($v) ? 'متوفر' : 'نفد حالياً']));
        $url = $this->url($p).($v->shopify_id ? '?variant='.$v->shopify_id : '');

        $cards = OutboundCards::generic([[
            'title' => $title,
            'subtitle' => $subtitle,
            'text' => implode("\n", array_filter([$title, $subtitle, $url])),
            'image_url' => self::jpeg($v->image_url) ?? $this->image($p),
            'url' => $url,
            'buttons' => [OutboundCards::webUrl(self::VIEW_BUTTON, $url)],
        ]]);

        return [
            'cards' => ['type' => 'generic', 'label' => self::WHATSAPP_LABEL, 'cards' => $cards['cards']],
            'text' => implode("\n", array_filter([$title, $subtitle, $url])),
        ];
    }

    /** In stock, or Shopify keeps selling it at zero (`inventory_policy: continue`). */
    public static function variantInStock(ProductVariant $v): bool
    {
        return (int) $v->inventory_quantity > 0 || $v->inventory_policy === 'continue';
    }

    /** The plain list that rides in the message body (the fallback where cards cannot be drawn). */
    public function fallbackText(Collection $products): string
    {
        return $products->map(fn (Product $p) => $this->line($p))->implode("\n\n");
    }

    /** «التفاصيل والمقاسات»: the price and every colour/size with its stock, from the synced catalog only. */
    public function detail(Product $p): string
    {
        $p->loadMissing('variants');
        $price = $this->price($p);
        $lines = [$p->title, $price !== '' ? 'السعر: '.$price : ''];

        foreach ($this->stockByColor($p) as $color => $sizes) {
            $lines[] = ($color === '' ? '' : $color.': ').implode('، ', $sizes);
        }

        $lines[] = $this->url($p);

        return implode("\n", array_filter($lines, 'filled'));
    }

    public function url(Product $p): string
    {
        return rtrim(BotSetting::current()->storeUrl(), '/').'/products/'.$p->handle;
    }

    /** Shopify's CDN re-encodes on request: WhatsApp takes JPEG/PNG only, and 800px is plenty for a card. */
    public function image(Product $p): ?string
    {
        $url = (string) ($p->image_url ?: $p->variants->firstWhere('image_url', '!=', null)?->image_url);

        return self::jpeg($url);
    }

    /** A public https picture every channel accepts, or null. */
    public static function jpeg(?string $url): ?string
    {
        $url = (string) $url;

        if ($url === '' || ! str_starts_with($url, 'https://')) {
            return null;
        }

        return str_contains($url, 'cdn.shopify.com') && ! str_contains($url, 'format=')
            ? $url.(str_contains($url, '?') ? '&' : '?').'width=800&format=jpg'
            : $url;
    }

    public function price(Product $p): string
    {
        $prices = $p->variants->map(fn ($v) => (float) $v->price)->filter(fn (float $x) => $x > 0);

        if ($prices->isEmpty()) {
            return '';
        }

        $fmt = fn (float $x) => rtrim(rtrim(number_format($x, 2, '.', ''), '0'), '.');

        return ($prices->min() === $prices->max() ? $fmt($prices->min()) : $fmt($prices->min()).' - '.$fmt($prices->max())).' جنيه';
    }

    private function subtitle(Product $p): string
    {
        $inStock = $p->variants->contains(fn ($v) => (int) $v->inventory_quantity > 0);

        return implode(' · ', array_filter([$this->price($p), $inStock ? 'متوفر' : 'نفد حالياً']));
    }

    private function line(Product $p): string
    {
        return implode("\n", array_filter([$p->title, $this->subtitle($p), $this->url($p)]));
    }

    /** @return array<string, list<string>> colour → "M (متوفر)" / "L (نفد)" */
    private function stockByColor(Product $p): array
    {
        $out = [];

        foreach ($p->variants as $v) {
            ['color' => $color, 'size' => $size] = VariantOptions::parse($v->title);
            $isDefault = $v->title === null || in_array($v->title, ['', 'Default', 'Default Title'], true);
            $label = $size ?? ($color === null && ! $isDefault ? (string) $v->title : '');
            $stock = (int) $v->inventory_quantity > 0 ? 'متوفر' : 'نفد';
            $out[$color ?? ''][] = trim($label.' '.($label === '' ? $stock : "({$stock})"));
        }

        return $out;
    }
}
