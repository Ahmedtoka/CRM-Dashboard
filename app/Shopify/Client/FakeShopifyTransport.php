<?php

namespace App\Shopify\Client;

use Illuminate\Support\Facades\File;

/**
 * Deterministic fake transport used when `crm.shopify.driver` is `fake`, so
 * the connection screen and every later Shopify feature work without a real
 * store. Answers the connection-test query with a demo shop and every
 * required scope granted, and supports the initial import minimally: shipping
 * profiles, `bulkOperationRunQuery` (writes a JSONL export of a small built-in
 * catalog to storage/app/shopify/fake-{stage}.jsonl) and a poll that is always
 * COMPLETED with a `file://` URL. Paged sync queries return empty pages.
 */
final class FakeShopifyTransport implements ShopifyTransport
{
    public function post(string $url, array $headers, array $body): array
    {
        $query = is_string($body['query'] ?? null) ? $body['query'] : '';

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
            $id = (string) ($body['variables']['id'] ?? '');
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
                'nodes' => [['profileLocationGroups' => [['locationGroupZones' => ['nodes' => [$this->zone()]]]]]],
            ]]);
        }

        foreach (['orders', 'customers', 'products'] as $resource) {
            if (str_contains($query, "{$resource}(")) {
                return $this->ok([$resource => ['edges' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]);
            }
        }

        return $this->ok([]);
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

    /** @return list<array<string, mixed>> */
    private function productRows(): array
    {
        $updated = now()->subDays(3)->utc()->toIso8601ZuluString();
        $rows = [];

        foreach ([[1, 'عباية كتان', 'ABY-LIN', '799.00'], [2, 'طرحة شيفون', 'SCF-CHF', '250.00']] as [$n, $title, $sku, $price]) {
            $productId = "gid://shopify/Product/90000{$n}";
            $rows[] = ['id' => $productId, 'title' => $title, 'handle' => strtolower($sku), 'status' => 'ACTIVE', 'vendor' => 'Demo',
                'productType' => null, 'tags' => [], 'updatedAt' => $updated, 'featuredImage' => null];

            foreach (['S', 'M'] as $i => $size) {
                $rows[] = ['id' => "gid://shopify/ProductVariant/90010{$n}{$i}", 'title' => $size, 'sku' => "{$sku}-{$size}", 'price' => $price,
                    'compareAtPrice' => null, 'barcode' => null, 'inventoryQuantity' => 10, 'inventoryPolicy' => 'DENY', 'updatedAt' => $updated,
                    'inventoryItem' => ['id' => "gid://shopify/InventoryItem/90020{$n}{$i}", 'requiresShipping' => true], 'image' => null,
                    '__parentId' => $productId];
            }
        }

        return $rows;
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

    /** @return list<array<string, mixed>> */
    private function orderRows(): array
    {
        $rows = [];

        foreach ($this->customers() as $n => $customer) {
            $orderId = 'gid://shopify/Order/90030'.$n;
            $created = now()->subDays(10 + $n)->utc()->toIso8601ZuluString();
            $money = fn (string $amount) => ['shopMoney' => ['amount' => $amount, 'currencyCode' => 'EGP']];
            $address = $customer['address'];
            unset($customer['address']);

            $rows[] = [
                'id' => $orderId, 'name' => '#'.(1001 + $n), 'email' => $customer['email'], 'phone' => null,
                'createdAt' => $created, 'updatedAt' => $created, 'processedAt' => $created, 'cancelledAt' => null, 'cancelReason' => null,
                'currencyCode' => 'EGP', 'displayFinancialStatus' => $n === 0 ? 'PAID' : 'PENDING', 'displayFulfillmentStatus' => 'UNFULFILLED',
                'note' => null, 'customAttributes' => [], 'paymentGatewayNames' => [$n === 0 ? 'paymob' : 'Cash on Delivery (COD)'],
                'currentSubtotalPriceSet' => $money('799.0'), 'currentTotalPriceSet' => $money('859.0'),
                'currentTotalDiscountsSet' => $money('0.0'), 'totalShippingPriceSet' => $money('60.0'),
                'shippingAddress' => $address, 'billingAddress' => null, 'shippingLine' => ['title' => 'شحن القاهرة والجيزة'],
                'customer' => $customer, 'fulfillments' => [], 'refunds' => [],
            ];
            $rows[] = ['id' => 'gid://shopify/LineItem/90040'.$n, 'title' => 'عباية كتان', 'name' => 'عباية كتان - S', 'sku' => 'ABY-LIN-S',
                'quantity' => 1, 'currentQuantity' => 1, 'variant' => ['id' => 'gid://shopify/ProductVariant/9001010'],
                'originalUnitPriceSet' => $money('799.0'), 'totalDiscountSet' => $money('0.0'), 'discountAllocations' => [], 'image' => null,
                '__parentId' => $orderId];
        }

        return $rows;
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

    private function zone(): array
    {
        return [
            'zone' => ['id' => 'gid://shopify/DeliveryZone/900700', 'name' => 'مصر', 'countries' => [
                ['code' => ['countryCode' => 'EG', 'restOfWorld' => false], 'provinces' => [['name' => 'Cairo', 'code' => 'C'], ['name' => 'Giza', 'code' => 'GZ']]],
            ]],
            'methodDefinitions' => ['nodes' => [[
                'id' => 'gid://shopify/DeliveryMethodDefinition/900800', 'name' => 'شحن القاهرة والجيزة', 'active' => true,
                'rateProvider' => ['id' => 'gid://shopify/DeliveryRateDefinition/900900', 'price' => ['amount' => '60.0', 'currencyCode' => 'EGP']],
                'methodConditions' => [],
            ]]],
        ];
    }
}
