<?php

namespace App\Bot\Flows\Returns;

use App\Bot\ArabicNormalizer;
use App\Enums\ShipmentStatus;
use App\Models\BotSetting;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ShipmentEvent;
use Carbon\CarbonImmutable;

/**
 * Per-item return eligibility for the `order_items` step (spec 2026-09-19 §2):
 * never returnable (owner keyword list against the product type, tags and
 * title), discounted (exchange only) and the 14-day window from delivery.
 */
class ReturnItems
{
    public const RETURN_DAYS = 14;

    /** A fulfilled order without a known delivery day counts as delivered this many days later. */
    public const FULFILLED_TO_DELIVERED_DAYS = 3;

    /** Keywords that read as "accessories" in the refusal message. */
    private const ACCESSORY_WORDS = ['اكسسوار', 'accessories', 'accessory'];

    /** Shopify's placeholder title for a product that has no options. */
    private const DEFAULT_VARIANT_TITLES = ['default title', 'default'];

    /** @var list<string>|null normalized keywords, read once per instance */
    private ?array $keywords = null;

    public function __construct(private readonly ArabicNormalizer $normalizer) {}

    /** The colour/size words of a line (Shopify's variant title), null for a product without options. */
    public function variantOf(OrderItem $item): ?string
    {
        $title = trim((string) ($item->variant_title ?: $item->variant?->title));

        return $title === '' || in_array(mb_strtolower($title), self::DEFAULT_VARIANT_TITLES, true) ? null : $title;
    }

    /** The owner keyword that makes this item non-returnable, or null. */
    public function nonReturnableKeyword(OrderItem $item): ?string
    {
        $product = $item->variant?->product;
        $tags = is_array($product?->tags) ? implode(' ', array_map('strval', $product->tags)) : (string) ($product?->tags ?? '');

        return $this->keywordIn(implode(' ', [$item->title, $product?->product_type, $tags, $product?->title]));
    }

    /** The owner keyword found in free text (a typed item name), or null. */
    public function keywordIn(string $text): ?string
    {
        $haystack = $this->normalizer->normalize($text);

        if (trim($haystack) === '') {
            return null;
        }

        foreach ($this->keywords() as $original => $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return $original;
            }
        }

        return null;
    }

    public function isAccessoryKeyword(string $keyword): bool
    {
        $k = $this->normalizer->normalize($keyword);

        foreach (self::ACCESSORY_WORDS as $w) {
            if (str_contains($k, $this->normalizer->normalize($w))) {
                return true;
            }
        }

        return false;
    }

    /** A discount allocated on the line, or a variant sold under its compare-at price: exchange only. */
    public function isDiscounted(OrderItem $item): bool
    {
        $compareAt = $item->variant?->compare_at_price;

        return (float) $item->discount > 0
            || ($compareAt !== null && (float) $compareAt > (float) $item->price);
    }

    /**
     * When the 14 days start: the delivery time (Shopify fulfillment or carrier), else the first
     * fulfillment + 3 days; null when unknown (the item is then allowed).
     */
    public function windowStart(Order $order): ?CarbonImmutable
    {
        if (($delivered = $this->deliveredAt($order)) !== null) {
            return $delivered;
        }

        $fulfilled = $order->fulfillments()->whereNotNull('shopify_created_at')->min('shopify_created_at');

        return $fulfilled !== null ? CarbonImmutable::parse($fulfilled)->addDays(self::FULFILLED_TO_DELIVERED_DAYS) : null;
    }

    public function deliveredAt(Order $order): ?CarbonImmutable
    {
        $shopify = $order->fulfillments()->whereNotNull('delivered_at')->min('delivered_at');

        if ($shopify !== null) {
            return CarbonImmutable::parse($shopify);
        }

        $shipment = $order->shipment;

        if ($shipment === null) {
            return null;
        }

        $event = $shipment->events()->where('status', ShipmentStatus::Delivered->value)->whereNotNull('occurred_at')->orderBy('occurred_at')->first();

        if ($event instanceof ShipmentEvent && $event->occurred_at !== null) {
            return CarbonImmutable::instance($event->occurred_at);
        }

        return $shipment->status === ShipmentStatus::Delivered && $shipment->last_event_at !== null
            ? CarbonImmutable::instance($shipment->last_event_at)
            : null;
    }

    /** Whether the 14 days from delivery are over (unknown delivery → still open). */
    public function windowClosed(Order $order, ?CarbonImmutable $now = null): bool
    {
        $start = $this->windowStart($order);

        return $start !== null && $start->addDays(self::RETURN_DAYS)->lessThan($now ?? CarbonImmutable::now());
    }

    /** @return array<string, string> original keyword → normalized */
    private function keywords(): array
    {
        if ($this->keywords === null) {
            $this->keywords = [];

            foreach (BotSetting::current()->nonReturnableKeywords() as $word) {
                $this->keywords[$word] = $this->normalizer->normalize($word);
            }
        }

        return $this->keywords;
    }
}
