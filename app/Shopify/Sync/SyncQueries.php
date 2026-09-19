<?php

namespace App\Shopify\Sync;

use InvalidArgumentException;

/**
 * GraphQL documents shared by the bulk importer and incremental sync. The node
 * selections are exactly what the mappers' GraphQL normalizers read.
 *
 * Cost (shopify.dev/docs/api/usage/limits): a normal query may not request more
 * than 1000 points; objects cost 1 and connections are sized by `first`, so
 * nested connections multiply. Every non-bulk document here is sized to stay
 * under that cap by estimatedWorstCaseCost(); nested connections that can be
 * longer than one page are completed with follow-up queries (nestedPage) so a
 * truncated list never reaches a mapper that deletes what is missing.
 *
 * Bulk operations are not cost-capped and ignore `first` ("The first argument is
 * optional and ignored if present"), so bulk documents carry no page sizes.
 *
 * @internal
 */
final class SyncQueries
{
    public const RESOURCES = ['products', 'customers', 'orders'];

    public const MAX_QUERY_COST = 1000;

    /** Top-level page size of the paged (non-bulk) query per resource. */
    public const PAGE_SIZES = ['products' => 10, 'customers' => 50, 'orders' => 6];

    /**
     * Resource node selection without its main nested connection. `{first:N}`
     * becomes "" in bulk documents and "(first: N)" in paged ones.
     */
    private const NODE = [
        'products' => <<<'GRAPHQL'
            id title handle status vendor productType tags updatedAt
            featuredImage { url }
        GRAPHQL,
        'customers' => <<<'GRAPHQL'
            id firstName lastName email phone updatedAt tags numberOfOrders
            amountSpent { amount currencyCode }
            emailMarketingConsent { marketingState }
            defaultAddress { id name firstName lastName phone address1 address2 city province provinceCode zip countryCodeV2 }
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
            fulfillments{first:5} { id status displayStatus createdAt updatedAt deliveredAt trackingInfo { company number url } }
            refunds{first:5} { id note createdAt totalRefundedSet { shopMoney { amount currencyCode } } }
        GRAPHQL,
    ];

    /**
     * The one list per resource a mapper replaces wholesale (missing rows are
     * deleted), so it must always be complete.
     */
    private const NESTED = [
        'products' => [
            'key' => 'variants', 'select' => 'variants', 'type' => 'Product', 'page' => 25, 'followUp' => 50,
            'node' => <<<'GRAPHQL'
                id title sku price compareAtPrice barcode inventoryQuantity inventoryPolicy updatedAt
                inventoryItem { id requiresShipping }
                image { url }
            GRAPHQL,
        ],
        'customers' => [
            'key' => 'addresses', 'select' => 'addresses: addressesV2', 'type' => 'Customer', 'page' => 10, 'followUp' => 100,
            'node' => 'id name firstName lastName phone address1 address2 city province provinceCode zip countryCodeV2',
        ],
        'orders' => [
            'key' => 'lineItems', 'select' => 'lineItems', 'type' => 'Order', 'page' => 10, 'followUp' => 50,
            'node' => <<<'GRAPHQL'
                id title name variantTitle sku quantity currentQuantity
                variant { id }
                originalUnitPriceSet { shopMoney { amount currencyCode } }
                totalDiscountSet { shopMoney { amount currencyCode } }
                discountAllocations { allocatedAmountSet { shopMoney { amount currencyCode } } }
                image { url }
            GRAPHQL,
        ],
    ];

    public const BULK_OPERATION = <<<'GRAPHQL'
        query bulkOperation($id: ID!) {
          node(id: $id) {
            ... on BulkOperation { id status errorCode objectCount fileSize url partialDataUrl createdAt completedAt }
          }
        }
    GRAPHQL;

    private const ZONE_NODE = <<<'GRAPHQL'
        zone { id name countries { code { countryCode restOfWorld } provinces { name code } } }
        methodDefinitions(first: 10) {
          pageInfo { hasNextPage }
          nodes {
            id name active
            rateProvider { ... on DeliveryRateDefinition { id price { amount currencyCode } } }
            methodConditions { field operator conditionCriteria { ... on MoneyV2 { amount currencyCode } } }
          }
        }
    GRAPHQL;

    /** First page of profiles with their first zones; more zones via SHIPPING_ZONES. */
    public const SHIPPING = <<<'GRAPHQL'
        query deliveryProfiles($cursor: String) {
          deliveryProfiles(first: 2, after: $cursor) {
            pageInfo { hasNextPage endCursor }
            nodes {
              id
              profileLocationGroups {
                locationGroup { id }
                locationGroupZones(first: 5) {
                  pageInfo { hasNextPage endCursor }
                  nodes { __ZONE__ }
                }
              }
            }
          }
        }
    GRAPHQL;

    /** Follow-up zone pages of one profile location group. */
    public const SHIPPING_ZONES = <<<'GRAPHQL'
        query deliveryProfileZones($profileId: ID!, $locationGroupId: ID!, $cursor: String) {
          deliveryProfile(id: $profileId) {
            profileLocationGroups(locationGroupId: $locationGroupId) {
              locationGroupZones(first: 15, after: $cursor) {
                pageInfo { hasNextPage endCursor }
                nodes { __ZONE__ }
              }
            }
          }
        }
    GRAPHQL;

    public static function shipping(): string
    {
        return str_replace('__ZONE__', self::ZONE_NODE, self::SHIPPING);
    }

    public static function shippingZones(): string
    {
        return str_replace('__ZONE__', self::ZONE_NODE, self::SHIPPING_ZONES);
    }

    /** `bulkOperationRunQuery` mutation with the export query inlined as a block string. */
    public static function bulkRun(string $resource, ?string $ordersSince = null): string
    {
        $nested = self::nested($resource);
        $filter = $resource === 'orders' && $ordersSince !== null
            ? "(query: \"created_at:>='{$ordersSince}'\")"
            : '';

        $node = self::sized(self::NODE[$resource], false)
            ." {$nested['select']} { edges { node { {$nested['node']} } } }";
        $inner = "{ {$resource}{$filter} { edges { node { {$node} } } } }";

        return "mutation {\n  bulkOperationRunQuery(query: \"\"\"\n{$inner}\n\"\"\") {\n    bulkOperation { id status }\n    userErrors { field message }\n  }\n}";
    }

    /** Paged query; variables: cursor, query. */
    public static function paged(string $resource): string
    {
        $nested = self::nested($resource);
        $size = self::PAGE_SIZES[$resource];
        $node = self::sized(self::NODE[$resource], true)
            ." {$nested['select']}(first: {$nested['page']}) { edges { node { {$nested['node']} } } pageInfo { hasNextPage endCursor } }";

        return "query sync(\$cursor: String, \$query: String) {\n"
            ."  {$resource}(first: {$size}, after: \$cursor, query: \$query, sortKey: UPDATED_AT) {\n"
            ."    edges { node { {$node} } }\n"
            ."    pageInfo { hasNextPage endCursor }\n"
            ."  }\n}";
    }

    /** Follow-up page of a resource's nested connection; variables: id, cursor. Response: data.node.{nestedKey}. */
    public static function nestedPage(string $resource): string
    {
        $nested = self::nested($resource);

        return "query nested(\$id: ID!, \$cursor: String) {\n"
            ."  node(id: \$id) {\n"
            ."    ... on {$nested['type']} {\n"
            ."      {$nested['select']}(first: {$nested['followUp']}, after: \$cursor) { edges { node { {$nested['node']} } } pageInfo { hasNextPage endCursor } }\n"
            ."    }\n  }\n}";
    }

    public static function nestedKey(string $resource): string
    {
        return self::nested($resource)['key'];
    }

    /**
     * Every document sent as a normal (cost-capped) query.
     *
     * @return array<string, string>
     */
    public static function cappedDocuments(): array
    {
        $documents = [
            'bulk_operation' => self::BULK_OPERATION,
            'shipping' => self::shipping(),
            'shipping_zones' => self::shippingZones(),
        ];

        foreach (self::RESOURCES as $resource) {
            $documents["paged_{$resource}"] = self::paged($resource);
            $documents["nested_{$resource}"] = self::nestedPage($resource);
        }

        return $documents;
    }

    /**
     * Worst-case requested cost following Shopify's rules: scalars 0, objects 1,
     * a connection (a field with a literal `first`/`last`) costs
     * 2 + first × (1 + cost of one node) + its own non-node fields (pageInfo);
     * `edges`/`nodes` (and an argument-less `node`) are free wrappers and inline
     * fragments add their fields. Nested `first` values therefore multiply.
     */
    public static function estimatedWorstCaseCost(string $document): int
    {
        preg_match_all('/"""[\s\S]*?"""|"(?:[^"\\\\]|\\\\.)*"|\.\.\.|[A-Za-z_][A-Za-z0-9_]*|-?\d+(?:\.\d+)?|[{}():$!=@\[\],]/', $document, $m);
        $tokens = $m[0];
        $i = 0;
        $depth = 0;

        // Skip the operation keyword, name and variable definitions.
        while ($i < count($tokens) && ! ($tokens[$i] === '{' && $depth === 0)) {
            $depth += $tokens[$i] === '(' ? 1 : ($tokens[$i] === ')' ? -1 : 0);
            $i++;
        }

        if ($i >= count($tokens)) {
            return 0;
        }

        return (int) array_sum(array_column(self::selection($tokens, $i), 'cost'));
    }

    /**
     * @param  list<string>  $t
     * @return list<array{name: string, cost: int}>
     */
    private static function selection(array $t, int &$i): array
    {
        $i++; // '{'
        $fields = [];

        while ($i < count($t) && $t[$i] !== '}') {
            if ($t[$i] === '...') {
                $i++;
                if (($t[$i] ?? null) === 'on') {
                    $i += 2;
                }
                if (($t[$i] ?? null) === '{') {
                    array_push($fields, ...self::selection($t, $i));
                }

                continue;
            }

            $name = $t[$i++];
            if (($t[$i] ?? null) === ':') {
                $name = $t[$i + 1] ?? $name; // alias: name
                $i += 2;
            }

            $first = null;
            $hasArgs = ($t[$i] ?? null) === '(';
            if ($hasArgs) {
                $depth = 0;
                do {
                    if ($t[$i] === '(') {
                        $depth++;
                    } elseif ($t[$i] === ')') {
                        $depth--;
                    } elseif (in_array($t[$i], ['first', 'last'], true) && ($t[$i + 1] ?? null) === ':' && is_numeric($t[$i + 2] ?? null)) {
                        $first = (int) $t[$i + 2];
                    }
                    $i++;
                } while ($depth > 0 && $i < count($t));
            }

            if (($t[$i] ?? null) !== '{') {
                $fields[] = ['name' => $name, 'cost' => 0];

                continue;
            }

            $children = self::selection($t, $i);
            $sum = (int) array_sum(array_column($children, 'cost'));

            if ($first !== null) {
                $perNode = (int) array_sum(array_column(array_filter($children, fn ($c) => in_array($c['name'], ['edges', 'nodes'], true)), 'cost'));
                $cost = 2 + $first * (1 + $perNode) + ($sum - $perNode);
            } elseif (in_array($name, ['edges', 'nodes'], true) || ($name === 'node' && ! $hasArgs)) {
                $cost = $sum;
            } else {
                $cost = 1 + $sum;
            }

            $fields[] = ['name' => $name, 'cost' => $cost];
        }

        $i++; // '}'

        return $fields;
    }

    /** @return array{key: string, select: string, type: string, page: int, followUp: int, node: string} */
    private static function nested(string $resource): array
    {
        return self::NESTED[$resource] ?? throw new InvalidArgumentException("Unknown Shopify sync resource [{$resource}].");
    }

    private static function sized(string $fields, bool $paged): string
    {
        return (string) preg_replace_callback('/\{first:(\d+)\}/', fn ($m) => $paged ? "(first: {$m[1]})" : '', $fields);
    }
}
