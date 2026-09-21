<?php

namespace App\Bot\Flows\Returns;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The product she wants in exchange, from a store link (2026-09-19, `product_link`
 * step): any URL whose path has `/products/{handle}` (also under
 * `/collections/x/`), with an optional `?variant=ID`. The handle is looked up in
 * the synced catalog first, then in Shopify through RemoteProductLookup (which
 * saves it to the catalog). Nothing about the order is involved.
 */
class ExchangeProducts
{
    /** A link as typed: with or without the scheme, stopped at whitespace. */
    private const URL_PATTERN = '~(?:https?://)?(?:[a-z0-9\-]+\.)+[a-z]{2,}(?::\d+)?/[^\s<>"\']*~iu';

    public function __construct(private readonly RemoteProductLookup $remote) {}

    /**
     * The first product link in the text.
     *
     * @return array{url:string, handle:string, variant_id:?string}|null
     */
    public function parse(string $text): ?array
    {
        if (! preg_match_all(self::URL_PATTERN, $text, $matches)) {
            return null;
        }

        foreach ($matches[0] as $raw) {
            $url = rtrim($raw, '.,،؛;:!?)]}»');
            $parts = parse_url(preg_match('~^https?://~i', $url) ? $url : 'https://'.$url);
            $path = rawurldecode((string) ($parts['path'] ?? ''));

            if (! preg_match('~/products/([^/?#]+)~u', $path, $m)) {
                continue;
            }

            $handle = mb_strtolower(trim($m[1]));

            if ($handle === '') {
                continue;
            }

            parse_str((string) ($parts['query'] ?? ''), $query);
            $variant = is_string($query['variant'] ?? null) && ctype_digit($query['variant']) ? $query['variant'] : null;

            return ['url' => $url, 'handle' => $handle, 'variant_id' => $variant];
        }

        return null;
    }

    /**
     * The product behind a parsed link, for the case: title, handle, url, price, image and, for a
     * `?variant=` link, the variant's title. Null when neither the catalog nor Shopify knows it.
     *
     * @param  array{url:string, handle:string, variant_id:?string}  $link
     * @return array{title:string, handle:string, url:string, price:?float, image:?string, variant_title:?string, variant_id:?string, product_id:int, source:string}|null
     */
    /**
     * @param  ?callable  $onRemoteLookup  called just before the store is asked, so the flow can
     *                                     tell her «ثانية بس، بشوف المنتج» instead of going silent
     */
    public function resolve(array $link, ?callable $onRemoteLookup = null): ?array
    {
        $source = 'catalog';
        $product = $this->local($link['handle']);

        if ($product === null) {
            if ($onRemoteLookup !== null) {
                $onRemoteLookup();
            }

            try {
                $product = $this->remote->byHandle($link['handle']);
            } catch (Throwable $e) {
                Log::info('flow.exchange_product_lookup_failed', ['handle' => $link['handle'], 'error' => $e->getMessage()]);
                $product = null;
            }

            $source = 'shopify';
        }

        if ($product === null) {
            return null;
        }

        $product->loadMissing('variants');
        $variant = $link['variant_id'] !== null ? $product->variants->firstWhere('shopify_id', $link['variant_id']) : null;
        $price = $variant?->price ?? $product->variants->whereNotNull('price')->min('price');

        return [
            'title' => (string) $product->title,
            'handle' => $link['handle'],
            'url' => $link['url'],
            'price' => $price !== null ? (float) $price : null,
            'image' => $variant?->image_url ?: $product->image_url,
            'variant_title' => $variant instanceof ProductVariant ? $this->variantTitle($variant) : null,
            'variant_id' => $link['variant_id'],
            'product_id' => (int) $product->id,
            'source' => $source,
        ];
    }

    private function local(string $handle): ?Product
    {
        return Product::query()->where('handle', $handle)->with('variants')->orderByDesc('id')->first();
    }

    private function variantTitle(ProductVariant $variant): ?string
    {
        $title = trim((string) $variant->title);

        return $title === '' || in_array(mb_strtolower($title), ['default title', 'default'], true) ? null : $title;
    }
}
