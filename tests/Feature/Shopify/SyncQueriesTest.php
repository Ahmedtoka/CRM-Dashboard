<?php

use App\Shopify\Sync\SyncQueries;

it('keeps every cost-capped document within Shopify\'s 1000-point query cost', function () {
    foreach (SyncQueries::cappedDocuments() as $name => $document) {
        expect(SyncQueries::estimatedWorstCaseCost($document))->toBeLessThanOrEqual(SyncQueries::MAX_QUERY_COST, "{$name} is over the cap")
            ->and($document)->not->toContain('first: $', "{$name} must size connections with literals so the cost is knowable");
    }
});

it('estimates cost with objects at one point and nested connections multiplying', function () {
    expect(SyncQueries::estimatedWorstCaseCost('query { products(first: 10) { edges { node { id featuredImage { url } } } pageInfo { hasNextPage } } }'))
        ->toBe(2 + 10 * (1 + 1) + 1)
        ->and(SyncQueries::estimatedWorstCaseCost('query q($c: String) { orders(first: 2, after: $c) { nodes { id lineItems(first: 3) { nodes { variant { id } } } } } }'))
        ->toBe(2 + 2 * (1 + (2 + 3 * (1 + 1))))
        ->and(SyncQueries::estimatedWorstCaseCost('query { node(id: "x") { ... on Order { id customer { id } } } }'))->toBe(2)
        // The sizes originally proposed for orders would be rejected by Shopify.
        ->and(SyncQueries::estimatedWorstCaseCost('query { orders(first: 25) { nodes { lineItems(first: 30) { nodes { variant { id } image { url } } } } } }'))
        ->toBeGreaterThan(SyncQueries::MAX_QUERY_COST);
});

it('leaves page sizes out of bulk documents, which Shopify does not cost-cap', function () {
    foreach (SyncQueries::RESOURCES as $resource) {
        expect(SyncQueries::bulkRun($resource, '2025-09-14'))->not->toContain('(first')->not->toContain('first:');
    }
});
