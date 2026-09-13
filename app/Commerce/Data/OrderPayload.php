<?php

namespace App\Commerce\Data;

use App\Models\Order;

/**
 * Everything the provider needs to create the order, built only from the
 * local order (prices, shipping and discount were fixed at creation).
 */
final readonly class OrderPayload
{
    /**
     * @param  array<int, array{variant_shopify_id: string, title: string, qty: int, price: string}>  $lineItems
     * @param  array<int, string>  $tags
     * @param  array<string, mixed>  $noteAttributes
     * @param  array<string, string>  $shippingAddress  firstName, lastName, phone, address1, city, provinceCode, countryCode
     * @param  array{title?: string, price?: string}  $shippingLine
     * @param  array{type: string, value: string, amount: string, reason: ?string}|null  $discount
     */
    public function __construct(
        public Order $order,
        public array $lineItems,
        public array $tags,
        public string $note,
        public array $noteAttributes,
        public ?string $customerId = null,
        public array $shippingAddress = [],
        public array $shippingLine = [],
        public ?array $discount = null,
    ) {}
}
