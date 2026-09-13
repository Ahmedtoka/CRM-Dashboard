<?php

namespace App\Shopify\Sync;

use InvalidArgumentException;

/**
 * GraphQL documents shared by the bulk importer and incremental sync. The node
 * selections are exactly what the mappers' GraphQL normalizers read.
 *
 * @internal
 */
final class SyncQueries
{
    public const RESOURCES = ['products', 'customers', 'orders'];

    /**
     * `{first}` becomes "" in bulk queries (bulk operations page nested connections
     * themselves) and "(first: 250)" in paged queries.
     */
    private const FIELDS = [
        'products' => <<<'GRAPHQL'
            id title handle status vendor productType tags updatedAt
            featuredImage { url }
            variants{first} { edges { node {
              id title sku price compareAtPrice barcode inventoryQuantity inventoryPolicy updatedAt
              inventoryItem { id requiresShipping }
              image { url }
            } } }
        GRAPHQL,
        'customers' => <<<'GRAPHQL'
            id firstName lastName email phone updatedAt tags numberOfOrders
            amountSpent { amount currencyCode }
            emailMarketingConsent { marketingState }
            defaultAddress { id name firstName lastName phone address1 address2 city province provinceCode zip countryCodeV2 }
            addresses: addressesV2{first} { edges { node {
              id name firstName lastName phone address1 address2 city province provinceCode zip countryCodeV2
            } } }
        GRAPHQL,
        'orders' => <<<'GRAPHQL'
            id name email phone createdAt updatedAt processedAt cancelledAt cancelReason currencyCode
            displayFinancialStatus displayFulfillmentStatus note paymentGatewayNames
            customAttributes { key value }
            currentSubtotalPriceSet { shopMoney { amount currencyCode } }
            currentTotalPriceSet { shopMoney { amount currencyCode } }
            currentTotalDiscountsSet { shopMoney { amount currencyCode } }
            totalShippingPriceSet { shopMoney { amount currencyCode } }
            shippingAddress { name firstName lastName phone address1 address2 city province provinceCode zip countryCodeV2 }
            billingAddress { name firstName lastName phone address1 address2 city province provinceCode zip countryCodeV2 }
            shippingLine { title }
            customer { id firstName lastName email phone updatedAt }
            fulfillments { id status displayStatus createdAt updatedAt trackingInfo { company number url } }
            refunds { id note createdAt totalRefundedSet { shopMoney { amount currencyCode } } }
            lineItems{first} { edges { node {
              id title name sku quantity currentQuantity
              variant { id }
              originalUnitPriceSet { shopMoney { amount currencyCode } }
              totalDiscountSet { shopMoney { amount currencyCode } }
              discountAllocations { allocatedAmountSet { shopMoney { amount currencyCode } } }
              image { url }
            } } }
        GRAPHQL,
    ];

    public const BULK_OPERATION = <<<'GRAPHQL'
        query bulkOperation($id: ID!) {
          node(id: $id) {
            ... on BulkOperation { id status errorCode objectCount fileSize url partialDataUrl }
          }
        }
    GRAPHQL;

    public const SHIPPING = <<<'GRAPHQL'
        query deliveryProfiles($cursor: String) {
          deliveryProfiles(first: 20, after: $cursor) {
            pageInfo { hasNextPage endCursor }
            nodes {
              profileLocationGroups {
                locationGroupZones(first: 250) {
                  nodes {
                    zone { id name countries { code { countryCode restOfWorld } provinces { name code } } }
                    methodDefinitions(first: 100) {
                      nodes {
                        id name active
                        rateProvider { ... on DeliveryRateDefinition { id price { amount currencyCode } } }
                        methodConditions { field operator conditionCriteria { ... on MoneyV2 { amount currencyCode } } }
                      }
                    }
                  }
                }
              }
            }
          }
        }
    GRAPHQL;

    /** `bulkOperationRunQuery` mutation with the export query inlined as a block string. */
    public static function bulkRun(string $resource, ?string $ordersSince = null): string
    {
        $filter = $resource === 'orders' && $ordersSince !== null
            ? "(query: \"created_at:>='{$ordersSince}'\")"
            : '';

        $inner = "{ {$resource}{$filter} { edges { node { ".self::fields($resource, '').' } } } }';

        return "mutation {\n  bulkOperationRunQuery(query: \"\"\"\n{$inner}\n\"\"\") {\n    bulkOperation { id status }\n    userErrors { field message }\n  }\n}";
    }

    /** Paged query; variables: first, cursor, query. */
    public static function paged(string $resource): string
    {
        $fields = self::fields($resource, '(first: 250)');

        return "query sync(\$first: Int!, \$cursor: String, \$query: String) {\n"
            ."  {$resource}(first: \$first, after: \$cursor, query: \$query, sortKey: UPDATED_AT) {\n"
            ."    edges { node { {$fields} } }\n"
            ."    pageInfo { hasNextPage endCursor }\n"
            ."  }\n}";
    }

    private static function fields(string $resource, string $first): string
    {
        $fields = self::FIELDS[$resource] ?? throw new InvalidArgumentException("Unknown Shopify sync resource [{$resource}].");

        return str_replace('{first}', $first, $fields);
    }
}
