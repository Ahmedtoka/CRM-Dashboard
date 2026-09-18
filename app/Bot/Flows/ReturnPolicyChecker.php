<?php

namespace App\Bot\Flows;

use App\Bot\ArabicNormalizer;
use App\Bot\Flow\Orders\OrderStatusText;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\CarbonImmutable;

/**
 * Staff-facing notes on a return/exchange request (design §3 "Policy notes").
 * They go on the case for a person to check; the customer is never refused
 * because of them. Rules whose data is missing are skipped.
 */
final class ReturnPolicyChecker
{
    public const RETURN_DAYS = 14;

    /** Days allowed for the order to reach her before the 14 days start. */
    public const DELIVERY_ALLOWANCE_DAYS = 7;

    /** Words in an item title that usually mean no exchange or refund. */
    private const EXCLUDED_WORDS = ['قطن', 'بونيه', 'تربون', 'بادي', 'اكسسوار', 'portable', 'بوركيني', 'كاش مايوه'];

    public function __construct(private readonly ArabicNormalizer $normalizer) {}

    /** @return list<string> */
    public function notes(array $data): array
    {
        $order = is_numeric($data['order_id'] ?? null) ? Order::query()->with('items.variant')->find((int) $data['order_id']) : null;
        $notes = [];

        $placedAt = $order !== null ? ($order->placed_at ?? $order->created_at) : null;
        $placedAt = $placedAt !== null ? CarbonImmutable::instance($placedAt) : $this->parse($data['order_placed_at'] ?? null);

        if ($placedAt !== null && $placedAt->lessThan(CarbonImmutable::now()->subDays(self::RETURN_DAYS + self::DELIVERY_ALLOWANCE_DAYS))) {
            $notes[] = 'غالبًا عدى 14 يوم من الاستلام (الأوردر بتاريخ '.$placedAt->setTimezone(OrderStatusText::TIMEZONE)->format('d/m').')';
        }

        if ($order === null) {
            return $notes;
        }

        foreach ($order->items as $item) {
            if ($this->isExcluded((string) $item->title)) {
                $notes[] = 'فيه منتج غالبًا غير قابل للاستبدال أو الاسترجاع: '.$item->title;
            }
        }

        if (($data['request'] ?? null) === 'refund' && $order->items->contains(fn (OrderItem $i) => $this->isDiscounted($i))) {
            $notes[] = 'القطعة عليها خصم: متاح استبدال فقط';
        }

        return $notes;
    }

    private function isExcluded(string $title): bool
    {
        $normalized = $this->normalizer->normalize($title);

        foreach (self::EXCLUDED_WORDS as $word) {
            if (str_contains($normalized, $this->normalizer->normalize($word))) {
                return true;
            }
        }

        return false;
    }

    /** A discount allocated on the line, or a variant sold under its compare-at price. */
    private function isDiscounted(OrderItem $item): bool
    {
        $compareAt = $item->variant?->compare_at_price;

        return (float) $item->discount > 0
            || ($compareAt !== null && (float) $compareAt > (float) $item->price);
    }

    private function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
