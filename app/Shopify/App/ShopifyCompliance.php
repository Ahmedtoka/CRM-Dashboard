<?php

namespace App\Shopify\App;

use App\Inbox\UserNotifier;
use App\Legal\PersonalDataEraser;
use App\Models\Customer;
use App\Models\Order;
use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Sync\Mappers\Payload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What the CRM does with each privacy webhook (shopify-app.md §1.4, owner decision on shop/redact):
 *  - customers/data_request: logged, admins told; the export is assembled by hand within 30 days.
 *  - customers/redact: that customer's CRM copy (customer row, its orders, addresses, identities)
 *    and the listed orders are erased, the same way the retention prune erases a customer.
 *  - shop/redact (48 h after an uninstall): logged, admins told, NOTHING deleted. The store's data
 *    is only ever removed by the owner running `php artisan shopify:purge-data`.
 * A request for a store other than the connected one is ignored.
 */
final class ShopifyCompliance
{
    public const TOPICS = ['customers/data_request', 'customers/redact', 'shop/redact'];

    /** Bell notification type for the Shopify app (`data.event` says what happened). */
    public const NOTIFICATION = 'shopify.app';

    public function __construct(
        private readonly IntegrationRepository $integrations,
        private readonly PersonalDataEraser $eraser,
        private readonly UserNotifier $notifier,
    ) {}

    /** @return bool false when the request was ignored (another store, unknown topic) */
    public function handle(string $topic, array $payload): bool
    {
        $shop = (string) ($payload['shop_domain'] ?? $payload['_shop_domain_header'] ?? '');
        $connected = (string) $this->integrations->current()?->shop_domain;

        if ($connected === '' || strcasecmp($shop, $connected) !== 0) {
            Log::warning('shopify.compliance.other_shop', ['topic' => $topic, 'shop' => $shop]);

            return false;
        }

        return match ($topic) {
            'customers/data_request' => $this->dataRequest($payload),
            'customers/redact' => $this->redactCustomer($payload),
            'shop/redact' => $this->shopRedact($shop),
            default => false,
        };
    }

    private function dataRequest(array $payload): bool
    {
        $requestId = (string) ($payload['data_request']['id'] ?? '');
        $customerId = Payload::id($payload['customer']['id'] ?? null);

        Log::warning('shopify.compliance.data_request', ['request_id' => $requestId, 'shopify_customer_id' => $customerId]);
        $this->notifier->notifyAdmins(self::NOTIFICATION, ['event' => 'data_request', 'request_id' => $requestId]);

        return true;
    }

    private function redactCustomer(array $payload): bool
    {
        $shopifyCustomerId = Payload::id($payload['customer']['id'] ?? null);
        $orderIds = array_values(array_filter(array_map(
            fn ($id) => Payload::id($id),
            is_array($payload['orders_to_redact'] ?? null) ? $payload['orders_to_redact'] : [],
        )));

        $counts = DB::transaction(function () use ($shopifyCustomerId, $orderIds) {
            $customerIds = $shopifyCustomerId === null
                ? []
                : Customer::where('shopify_customer_id', $shopifyCustomerId)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $erased = $this->eraser->eraseCustomers($customerIds);
            $orders = $orderIds === [] ? 0 : Order::withLoadTest()->whereIn('shopify_order_id', $orderIds)->delete();

            return ['customers' => count($customerIds), 'orders' => $erased['orders'] + $orders];
        });

        Log::warning('shopify.compliance.customer_redacted', ['shopify_customer_id' => $shopifyCustomerId] + $counts);

        return true;
    }

    private function shopRedact(string $shop): bool
    {
        Log::warning('shopify.compliance.shop_redact', ['shop' => $shop, 'action' => 'none: purge by hand with shopify:purge-data']);
        $this->notifier->notifyAdmins(self::NOTIFICATION, ['event' => 'shop_redact', 'shop_domain' => $shop]);

        return true;
    }
}
