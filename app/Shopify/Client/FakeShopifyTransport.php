<?php

namespace App\Shopify\Client;

use Database\Seeders\Demo\ArabicCorpus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Deterministic fake transport used when `crm.shopify.driver` is `fake`, so
 * the connection screen, the demo seeder and every later Shopify feature work
 * without a real store (Task 10: fake store parity).
 *
 * Answers the connection-test query with a demo shop and every required
 * scope granted, and mirrors the real store closely enough for the whole
 * connect → import → order flow to run against it:
 *  - `deliveryProfiles`: the 27 governorates grouped into the demo's 5 rate
 *    tiers (`Database\Seeders\Demo\ArabicCorpus::cities()` is the single
 *    source of truth for that grouping), plus a "مستعجل" (express) rate on
 *    top of the Cairo/Giza tier.
 *  - `bulkOperationRunQuery` writes a JSONL export of the demo catalog
 *    (`ArabicCorpus::products()`) to storage/app/shopify/fake-{stage}.jsonl,
 *    and a small deterministic batch of store-placed orders, so the demo
 *    seeder's `BulkImporter::start()` call imports a real-looking catalog
 *    through the real mappers instead of two placeholder items.
 *  - `orderCreate`, `draftOrderCreate`, `customerCreate`, the customer/phone
 *    lookup query, `orderCancel` and the webhook subscription
 *    create/delete/list calls, so the *live* commerce/webhook code paths
 *    (crm.drivers.commerce = 'live') work end to end against this fake
 *    transport, not just the initial import.
 *  - the `orderByTag`/`draftByTag` adoption lookups: every order/draft
 *    created above is remembered with its tags, customAttributes and
 *    createdAt, and returned for a matching `tag:'...'` query.
 *
 * A poll is always COMPLETED with a `file://` URL; paged sync queries (used
 * by incremental sync, not the initial import) return empty pages.
 */
final class FakeShopifyTransport implements ShopifyTransport
{
    /** Roughly the demo seeder's target of ~20% store-origin orders. */
    private const STORE_ORDER_COUNT = 75;

    private const STORE_CUSTOMER_COUNT = 15;

    /** @var array<string, string> phone (E.164) => fake Shopify customer gid, created via customerCreate(). */
    private static array $customersByPhone = [];

    private static int $customerSeq = 0;

    private static int $orderSeq = 0;

    private static int $draftSeq = 0;

    /** @var array<string, array<string, mixed>> topic => webhookSubscription fields, created via webhookSubscriptionCreate(). */
    private static array $webhooks = [];

    /** @var array<int, array{kind: string, tags: array<int, string>, node: array<string, mixed>}> orders/drafts created via orderCreate()/draftOrderCreate(). */
    private static array $storeOrders = [];

    /** Clears every static in-memory store; called from Tests\TestCase::setUp(). */
    public static function reset(): void
    {
        self::$customersByPhone = [];
        self::$customerSeq = 0;
        self::$orderSeq = 0;
        self::$draftSeq = 0;
        self::$webhooks = [];
        self::$storeOrders = [];
    }

    public function post(string $url, array $headers, array $body): array
    {
        $query = is_string($body['query'] ?? null) ? $body['query'] : '';
        $variables = is_array($body['variables'] ?? null) ? $body['variables'] : [];

        if (str_contains($query, 'currentAppInstallation')) {
            return $this->ok([
                'shop' => [
                    'name' => 'متجر تجريبي',
                    'currencyCode' => 'EGP',
                ],
                'currentAppInstallation' => [
                    'accessScopes' => array_map(
                        fn (string $scope): array => ['handle' => $scope],
                        config('crm.shopify.required_scopes', []),
                    ),
                ],
            ]);
        }

        if (str_contains($query, 'bulkOperationRunQuery')) {
            $stage = $this->stageFrom($query);
            $this->writeExport($stage);

            return $this->ok(['bulkOperationRunQuery' => [
                'bulkOperation' => ['id' => "gid://shopify/BulkOperation/fake-{$stage}", 'status' => 'CREATED'],
                'userErrors' => [],
            ]]);
        }

        if (str_contains($query, 'on BulkOperation')) {
            $id = (string) ($variables['id'] ?? '');
            $stage = str_contains($id, 'fake-') ? substr($id, strrpos($id, 'fake-') + 5) : 'products';
            $path = $this->exportPath($stage);

            if (! is_file($path)) {
                $this->writeExport($stage);
            }

            return $this->ok(['node' => [
                'id' => $id,
                'status' => 'COMPLETED',
                'errorCode' => null,
                'objectCount' => (string) count(file($path, FILE_SKIP_EMPTY_LINES)),
                'url' => 'file://'.$path,
                'partialDataUrl' => null,
            ]]);
        }

        if (str_contains($query, 'deliveryProfiles')) {
            return $this->ok(['deliveryProfiles' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'nodes' => [['profileLocationGroups' => [['locationGroupZones' => ['nodes' => $this->zones()]]]]],
            ]]);
        }

        // The customer/phone lookup (App\Commerce\ShopifyCommerceProvider::ensureCustomer())
        // is a distinct named query, checked before the generic customers()/orders()/
        // products() fallback below (which answers in a different, `edges`-only shape).
        if (str_contains($query, 'customerByPhone')) {
            $raw = (string) ($variables['query'] ?? '');
            $phone = str_starts_with($raw, 'phone:') ? substr($raw, 6) : null;
            $id = $phone !== null ? (self::$customersByPhone[$phone] ?? null) : null;

            return $this->ok(['customers' => ['nodes' => $id !== null ? [['id' => $id]] : []]]);
        }

        // OrderService adoption lookups (ShopifyCommerceProvider::findSubmittedOrder()).
        if (str_contains($query, 'orderByTag') || str_contains($query, 'draftByTag')) {
            $kind = str_contains($query, 'draftByTag') ? 'draft' : 'order';
            $raw = (string) ($variables['query'] ?? '');
            $tag = preg_match("/^tag:'(.*)'$/", $raw, $m) === 1 ? $m[1] : null;
            $nodes = array_values(array_map(
                fn (array $row) => $row['node'],
                array_filter(self::$storeOrders, fn (array $row) => $row['kind'] === $kind && $tag !== null && in_array($tag, $row['tags'], true)),
            ));

            return $this->ok([$kind === 'draft' ? 'draftOrders' : 'orders' => ['nodes' => array_slice($nodes, 0, 5)]]);
        }

        if (str_contains($query, 'customerCreate(')) {
            self::$customerSeq++;
            $id = 'gid://shopify/Customer/fake-c'.self::$customerSeq;
            $input = is_array($variables['input'] ?? null) ? $variables['input'] : [];
            $phone = is_string($input['phone'] ?? null) ? $input['phone'] : null;

            if ($phone !== null && $phone !== '') {
                self::$customersByPhone[$phone] = $id;
            }

            return $this->ok(['customerCreate' => ['customer' => ['id' => $id], 'userErrors' => []]]);
        }

        if (str_contains($query, 'orderCreate(')) {
            self::$orderSeq++;
            $order = ['id' => 'gid://shopify/Order/fake-o'.self::$orderSeq, 'name' => '#F'.(9000 + self::$orderSeq)];
            $this->remember('order', is_array($variables['order'] ?? null) ? $variables['order'] : [], $order);

            return $this->ok(['orderCreate' => [
                'order' => $order,
                'userErrors' => [],
            ]]);
        }

        if (str_contains($query, 'draftOrderCreate(')) {
            self::$draftSeq++;
            $input = is_array($variables['input'] ?? null) ? $variables['input'] : [];
            $id = 'gid://shopify/DraftOrder/fake-d'.self::$draftSeq;

            $draft = [
                'id' => $id,
                'name' => '#D'.self::$draftSeq,
                'invoiceUrl' => "https://demo-store.myshopify.com/{$id}/invoices/fake",
                'totalPriceSet' => ['shopMoney' => ['amount' => $this->draftTotal($input), 'currencyCode' => 'EGP']],
            ];
            $this->remember('draft', $input, $draft);

            return $this->ok(['draftOrderCreate' => [
                'draftOrder' => $draft,
                'userErrors' => [],
            ]]);
        }

        if (str_contains($query, 'mutation orderCancel(')) {
            return $this->ok(['orderCancel' => ['job' => ['id' => 'gid://shopify/Job/fake-cancel'], 'orderCancelUserErrors' => []]]);
        }

        if (str_contains($query, 'webhookSubscriptionCreate(')) {
            $topic = (string) ($variables['topic'] ?? '');
            $sub = is_array($variables['webhookSubscription'] ?? null) ? $variables['webhookSubscription'] : [];
            $fields = ['id' => 'gid://shopify/WebhookSubscription/fake-'.Str::slug($topic), 'topic' => $topic];
            $fields = isset($sub['uri'])
                ? $fields + ['uri' => $sub['uri']]
                : $fields + ['endpoint' => ['callbackUrl' => $sub['callbackUrl'] ?? null]];
            self::$webhooks[$topic] = $fields;

            return $this->ok(['webhookSubscriptionCreate' => ['webhookSubscription' => $fields, 'userErrors' => []]]);
        }

        if (str_contains($query, 'webhookSubscriptionDelete(')) {
            $id = (string) ($variables['id'] ?? '');

            foreach (self::$webhooks as $topic => $sub) {
                if (($sub['id'] ?? null) === $id) {
                    unset(self::$webhooks[$topic]);
                }
            }

            return $this->ok(['webhookSubscriptionDelete' => ['deletedWebhookSubscriptionId' => $id, 'userErrors' => []]]);
        }

        if (str_contains($query, 'webhookSubscriptions(')) {
            return $this->ok(['webhookSubscriptions' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'nodes' => array_values(self::$webhooks),
            ]]);
        }

        foreach (['orders', 'customers', 'products'] as $resource) {
            if (str_contains($query, "{$resource}(")) {
                return $this->ok([$resource => ['edges' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]);
            }
        }

        return $this->ok([]);
    }

    /**
     * @param  array<string, mixed>  $input  the orderCreate `order` / draftOrderCreate `input`
     * @param  array<string, mixed>  $node
     */
    private function remember(string $kind, array $input, array $node): void
    {
        self::$storeOrders[] = [
            'kind' => $kind,
            'tags' => array_values(array_map('strval', (array) ($input['tags'] ?? []))),
            'node' => $node + [
                'createdAt' => now()->toIso8601String(),
                'customAttributes' => array_values(array_filter((array) ($input['customAttributes'] ?? []), 'is_array')),
            ],
        ];
    }

    private function ok(array $data): array
    {
        return ['status' => 200, 'json' => ['data' => $data], 'headers' => []];
    }

    private function stageFrom(string $query): string
    {
        return str_contains($query, 'orders') ? 'orders' : (str_contains($query, 'customers') ? 'customers' : 'products');
    }

    private function exportPath(string $stage): string
    {
        return storage_path("app/shopify/fake-{$stage}.jsonl");
    }

    private function writeExport(string $stage): void
    {
        $lines = array_map(fn (array $row) => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), match ($stage) {
            'orders' => $this->orderRows(),
            'customers' => $this->customerRows(),
            default => $this->productRows(),
        });

        File::ensureDirectoryExists(dirname($this->exportPath($stage)));
        File::put($this->exportPath($stage), implode("\n", $lines)."\n");
    }

    /**
     * The demo catalog (`ArabicCorpus::products()`), shaped exactly like a real
     * bulk products export: one parent line per product, one child line
     * (`__parentId`) per variant (size × color, or one per color when the
     * product has no sizes).
     *
     * @return list<array<string, mixed>>
     */
    private function productRows(): array
    {
        $updated = now()->subDays(31)->utc()->toIso8601ZuluString();
        $rows = [];

        foreach (ArabicCorpus::products() as $i => $def) {
            $productId = "gid://shopify/Product/{$this->numberFor('product', $i)}";
            $rows[] = [
                'id' => $productId, 'title' => $def['title'], 'handle' => Str::slug($def['title']).'-'.$i,
                'status' => 'ACTIVE', 'vendor' => 'Demo', 'productType' => null, 'tags' => [],
                'updatedAt' => $updated, 'featuredImage' => null,
            ];

            foreach ($this->variantSpecs($def) as $j => [$size, $color]) {
                $variantId = "gid://shopify/ProductVariant/{$this->numberFor('variant', $i, $j)}";
                $title = trim(implode(' - ', array_filter([$size, $color])));
                $price = $this->tieredAmount($def['price_min'], $def['price_max'], $i, $j);

                $rows[] = [
                    'id' => $variantId, 'title' => $title !== '' ? $title : 'Default',
                    'sku' => 'DEMO-'.$i.'-'.$j, 'price' => $price, 'compareAtPrice' => null, 'barcode' => null,
                    'inventoryQuantity' => 10 + $this->spread($i, $j + 100, 71), 'inventoryPolicy' => 'DENY',
                    'updatedAt' => $updated,
                    'inventoryItem' => ['id' => "gid://shopify/InventoryItem/{$this->numberFor('inventory_item', $i, $j)}", 'requiresShipping' => true],
                    'image' => null, '__parentId' => $productId,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array{sizes: bool, colors: list<string>}  $def
     * @return list<array{0: ?string, 1: string}> [size, color] pairs, one per variant
     */
    private function variantSpecs(array $def): array
    {
        if (! $def['sizes']) {
            return array_map(fn (string $color) => [null, $color], $def['colors']);
        }

        $sizes = ['S', 'M', 'L', 'XL'];

        return array_map(fn (string $size, int $j) => [$size, $def['colors'][$j % count($def['colors'])]], $sizes, array_keys($sizes));
    }

    /** @return list<array<string, mixed>> */
    private function customerRows(): array
    {
        $rows = [];

        foreach ($this->customers() as $customer) {
            $address = $customer['address'];
            unset($customer['address']);
            $rows[] = $customer + ['defaultAddress' => $address, 'tags' => [], 'numberOfOrders' => '1',
                'amountSpent' => ['amount' => '0.0', 'currencyCode' => 'EGP'], 'emailMarketingConsent' => null];
            $rows[] = $address + ['__parentId' => $customer['id']];
        }

        return $rows;
    }

    /**
     * A deterministic batch of orders placed directly on the storefront (no
     * matching CRM conversation), so `OrderMapper` creates them with
     * `source = store` — roughly the demo seeder's ~20% target once combined
     * with the chat-created orders from the 30-day replay. Each references a
     * fresh embedded customer (mapped through `CustomerMapper` the same way a
     * real order's inline `customer` node would be) and a variant from the
     * catalog above, spread across the 5 shipping tiers.
     *
     * @return list<array<string, mixed>>
     */
    private function orderRows(): array
    {
        $products = ArabicCorpus::products();
        $customers = $this->storeCustomers();
        $rows = [];

        for ($n = 0; $n < self::STORE_ORDER_COUNT; $n++) {
            $customer = $customers[$n % count($customers)];
            $productIndex = $n % count($products);
            $def = $products[$productIndex];
            $variants = $this->variantSpecs($def);
            $variantIndex = $n % count($variants);
            $variantId = "gid://shopify/ProductVariant/{$this->numberFor('variant', $productIndex, $variantIndex)}";
            $qty = 1 + ($n % 2);
            $price = $this->tieredAmount($def['price_min'], $def['price_max'], $productIndex, $variantIndex);
            $subtotal = number_format((float) $price * $qty, 2, '.', '');
            $shippingFee = number_format($this->feeForProvince($customer['provinceCode']), 2, '.', '');
            $total = number_format((float) $subtotal + (float) $shippingFee, 2, '.', '');
            $orderId = "gid://shopify/Order/{$this->numberFor('store_order', $n)}";
            $created = now()->subDays(31)->addHours($n)->utc()->toIso8601ZuluString();
            $money = fn (string $amount) => ['shopMoney' => ['amount' => $amount, 'currencyCode' => 'EGP']];
            $isCod = $n % 3 !== 0;

            $rows[] = [
                'id' => $orderId, 'name' => '#S'.(2000 + $n), 'email' => null, 'phone' => $customer['phone'],
                'createdAt' => $created, 'updatedAt' => $created, 'processedAt' => $created, 'cancelledAt' => null, 'cancelReason' => null,
                'currencyCode' => 'EGP', 'displayFinancialStatus' => $isCod ? 'PENDING' : 'PAID', 'displayFulfillmentStatus' => 'UNFULFILLED',
                'note' => null, 'customAttributes' => [], 'paymentGatewayNames' => [$isCod ? 'Cash on Delivery (COD)' : 'paymob'],
                'currentSubtotalPriceSet' => $money($subtotal), 'currentTotalPriceSet' => $money($total),
                'currentTotalDiscountsSet' => $money('0.0'), 'totalShippingPriceSet' => $money($shippingFee),
                'shippingAddress' => [
                    'id' => "gid://shopify/MailingAddress/{$this->numberFor('store_address', $n)}",
                    'name' => $customer['firstName'].' '.$customer['lastName'], 'phone' => $customer['phone'],
                    'address1' => 'شارع '.(1 + ($n % 40)), 'address2' => null, 'city' => $customer['provinceName'],
                    'province' => $customer['provinceName'], 'provinceCode' => $customer['provinceCode'], 'zip' => null, 'countryCodeV2' => 'EG',
                ],
                'billingAddress' => null, 'shippingLine' => ['title' => 'شحن '.$customer['provinceName']],
                'customer' => ['id' => $customer['id'], 'firstName' => $customer['firstName'], 'lastName' => $customer['lastName'],
                    'email' => null, 'phone' => $customer['phone'], 'updatedAt' => $created],
                'fulfillments' => [], 'refunds' => [],
            ];
            $rows[] = [
                'id' => "gid://shopify/LineItem/{$this->numberFor('store_line', $n)}", 'title' => $def['title'],
                'name' => $def['title'], 'sku' => 'DEMO-'.$productIndex.'-'.$variantIndex, 'quantity' => $qty, 'currentQuantity' => $qty,
                'variant' => ['id' => $variantId], 'originalUnitPriceSet' => $money($price), 'totalDiscountSet' => $money('0.0'),
                'discountAllocations' => [], 'image' => null, '__parentId' => $orderId,
            ];
        }

        return $rows;
    }

    /**
     * ~15 synthetic storefront customers (drawn from the same demo name pool as
     * the seeder), spread evenly across every governorate so the generated
     * orders exercise every shipping tier.
     *
     * @return list<array{id: string, firstName: string, lastName: string, phone: string, provinceCode: string, provinceName: string}>
     */
    private function storeCustomers(): array
    {
        $names = array_slice(ArabicCorpus::names(), 0, self::STORE_CUSTOMER_COUNT);
        $provinceCodes = array_keys(config('crm.eg_provinces', []));
        $provinces = config('crm.eg_provinces', []);
        $customers = [];

        foreach ($names as $i => $name) {
            $parts = preg_split('/\s+/u', trim($name), 2) ?: [$name];
            $code = $provinceCodes[$i % count($provinceCodes)];

            $customers[] = [
                'id' => "gid://shopify/Customer/{$this->numberFor('store_customer', $i)}",
                'firstName' => $parts[0] ?? $name,
                'lastName' => $parts[1] ?? '',
                'phone' => '+2010'.str_pad((string) (1000000 + $i), 7, '0', STR_PAD_LEFT),
                'provinceCode' => $code,
                'provinceName' => $provinces[$code],
            ];
        }

        return $customers;
    }

    /** @return list<array<string, mixed>> the 5 rate-tier zones covering all 27 governorates (Task 10 brief) */
    private function zones(): array
    {
        $codeByName = array_flip(config('crm.eg_provinces', []));
        $groups = [];

        foreach (ArabicCorpus::cities() as $city) {
            $groups[(string) $city['fee']][] = $city['name_ar'];
        }

        $zones = [];
        $zoneNum = 0;
        $rateNum = 0;

        foreach ($groups as $fee => $names) {
            $zoneNum++;
            $fee = (float) $fee;
            $title = $this->zoneTitle($fee);
            $provinces = array_map(fn (string $name) => ['name' => $name, 'code' => $codeByName[$name] ?? null], $names);

            $rateNum++;
            $methods = [$this->rateMethod($rateNum, 'شحن '.$title, $fee)];

            // "مستعجل" (express): the Cairo/Giza tier only, +40 EGP over the normal rate.
            if ((int) $fee === 60) {
                $rateNum++;
                $methods[] = $this->rateMethod($rateNum, 'شحن مستعجل', $fee + 40);
            }

            $zones[] = [
                'zone' => [
                    'id' => "gid://shopify/DeliveryZone/{$this->numberFor('zone', $zoneNum)}",
                    'name' => $title,
                    'countries' => [['code' => ['countryCode' => 'EG', 'restOfWorld' => false], 'provinces' => $provinces]],
                ],
                'methodDefinitions' => ['nodes' => $methods],
            ];
        }

        return $zones;
    }

    /** @return array<string, mixed> */
    private function rateMethod(int $rateNum, string $name, float $price): array
    {
        return [
            'id' => "gid://shopify/DeliveryMethodDefinition/{$this->numberFor('rate', $rateNum)}",
            'name' => $name,
            'active' => true,
            'rateProvider' => [
                'id' => "gid://shopify/DeliveryRateDefinition/{$this->numberFor('rate_provider', $rateNum)}",
                'price' => ['amount' => number_format($price, 2, '.', ''), 'currencyCode' => 'EGP'],
            ],
            'methodConditions' => [],
        ];
    }

    private function zoneTitle(float $fee): string
    {
        return match ((int) $fee) {
            60 => 'القاهرة والجيزة',
            70 => 'الإسكندرية',
            75 => 'الدلتا والقناة',
            90 => 'الصعيد',
            default => 'مناطق نائية',
        };
    }

    /** @var array<string, float>|null memoized province code => demo shipping fee */
    private static ?array $feeByProvince = null;

    /** The demo shipping fee (spec Task 10 brief) for one of the 27 governorate codes. */
    private function feeForProvince(string $code): float
    {
        self::$feeByProvince ??= $this->buildFeeByProvince();

        return self::$feeByProvince[$code] ?? 60.0;
    }

    /** @return array<string, float> */
    private function buildFeeByProvince(): array
    {
        $feeByName = [];

        foreach (ArabicCorpus::cities() as $city) {
            $feeByName[$city['name_ar']] = (float) $city['fee'];
        }

        $map = [];

        foreach (config('crm.eg_provinces', []) as $code => $name) {
            if (isset($feeByName[$name])) {
                $map[$code] = $feeByName[$name];
            }
        }

        return $map;
    }

    /** What a live `draftOrderCreate` would total the draft at: lines (already price-overridden) + shipping − discount. */
    private function draftTotal(array $input): string
    {
        $subtotal = 0.0;

        foreach ((array) ($input['lineItems'] ?? []) as $li) {
            $subtotal += (float) ($li['priceOverride']['amount'] ?? 0) * (int) ($li['quantity'] ?? 1);
        }

        $discount = 0.0;

        if (is_array($input['appliedDiscount'] ?? null)) {
            $value = (float) ($input['appliedDiscount']['value'] ?? 0);
            $discount = ($input['appliedDiscount']['valueType'] ?? null) === 'PERCENTAGE' ? $subtotal * $value / 100 : $value;
        }

        $shipping = (float) ($input['shippingLine']['priceWithCurrency']['amount'] ?? 0);

        return number_format(max(0.0, $subtotal - $discount) + $shipping, 2, '.', '');
    }

    /** @return list<array<string, mixed>> */
    private function customers(): array
    {
        $updated = now()->subDays(5)->utc()->toIso8601ZuluString();

        return [
            ['id' => 'gid://shopify/Customer/900500', 'firstName' => 'منى', 'lastName' => 'أحمد', 'email' => 'mona.demo@example.com',
                'phone' => '+201000000001', 'updatedAt' => $updated,
                'address' => ['id' => 'gid://shopify/MailingAddress/900600', 'name' => 'منى أحمد', 'phone' => '01000000001',
                    'address1' => '12 شارع التحرير', 'address2' => null, 'city' => 'القاهرة', 'province' => 'Cairo', 'provinceCode' => 'C',
                    'zip' => null, 'countryCodeV2' => 'EG']],
            ['id' => 'gid://shopify/Customer/900501', 'firstName' => 'سارة', 'lastName' => 'محمود', 'email' => null,
                'phone' => '+201000000002', 'updatedAt' => $updated,
                'address' => ['id' => 'gid://shopify/MailingAddress/900601', 'name' => 'سارة محمود', 'phone' => '01000000002',
                    'address1' => '8 شارع سعد زغلول', 'address2' => null, 'city' => 'الإسكندرية', 'province' => 'Alexandria', 'provinceCode' => 'ALX',
                    'zip' => null, 'countryCodeV2' => 'EG']],
        ];
    }

    /**
     * Deterministic price within [$min, $max], rounded to the nearest 25 (mirrors
     * `Database\Seeders\DemoSeeder`'s own pricing), without consuming the global
     * `mt_rand()` sequence that the seeder relies on for reproducibility.
     */
    private function tieredAmount(int $min, int $max, int $i, int $j): string
    {
        $raw = $min + $this->spread($i, $j, max(1, $max - $min + 1));

        return number_format((float) ((int) (round($raw / 25) * 25)), 2, '.', '');
    }

    /** A cheap, deterministic 0..($mod-1) spread — a stand-in for randomness that never touches mt_rand(). */
    private function spread(int $i, int $j, int $mod): int
    {
        if ($mod <= 0) {
            return 0;
        }

        return (($i * 97 + $j * 31 + 17) * 2654435761) % $mod;
    }

    /** A stable-ish numeric id string for a fake gid, namespaced by $kind so different resources never collide. */
    private function numberFor(string $kind, int ...$parts): string
    {
        $base = match ($kind) {
            'product' => 500_000,
            'variant' => 510_000,
            'inventory_item' => 520_000,
            'store_customer' => 600_000,
            'store_order' => 610_000,
            'store_line' => 620_000,
            'store_address' => 625_000,
            'zone' => 630_000,
            'rate' => 640_000,
            'rate_provider' => 650_000,
            default => 690_000,
        };

        return (string) ($base + ($parts[0] ?? 0) * 100 + ($parts[1] ?? 0));
    }
}
