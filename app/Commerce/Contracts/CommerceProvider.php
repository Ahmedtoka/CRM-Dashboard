<?php

namespace App\Commerce\Contracts;

use App\Commerce\Data\CommerceResult;
use App\Commerce\Data\OrderPayload;
use App\Commerce\Data\OrderStatusUpdate;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\Request;

interface CommerceProvider
{
    /**
     * Returns the store customer id for this customer: the linked id, else a
     * customer found by phone, else a newly created one.
     *
     * @param  array<string, string>  $shippingAddress
     */
    public function ensureCustomer(Customer $customer, array $shippingAddress): string;

    /**
     * The store order (COD) or draft (payment link) already created for this
     * CRM order, found by its `crm-order-{id}` tag; null when there is none.
     */
    public function findSubmittedOrder(Order $order): ?CommerceResult;

    public function createCodOrder(OrderPayload $payload): CommerceResult;

    public function createPaymentLink(OrderPayload $payload): CommerceResult;

    /**
     * Cancels the order on the store: a real order is cancelled (restocking when
     * asked, never refunding), an unpaid draft order (payment link) is deleted.
     * Orders never sent succeed as a no-op.
     */
    public function cancelOrder(Order $order, bool $restock = true): CommerceResult;

    public function syncProducts(): int;

    public function verifyWebhook(Request $request): bool;

    public function parseWebhook(string $topic, array $payload): ?OrderStatusUpdate;
}
