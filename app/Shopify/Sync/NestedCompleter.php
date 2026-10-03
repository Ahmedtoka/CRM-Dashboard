<?php

namespace App\Shopify\Sync;

use App\Shopify\Client\ShopifyClient;
use App\Shopify\Sync\Mappers\Payload;
use RuntimeException;

/**
 * Fetches the remaining pages of a node's nested list (variants, addresses,
 * line items) before it reaches a mapper: the mappers delete whatever is
 * missing from that list. Shared by IncrementalSync and OrderRefresher.
 *
 * @internal
 */
final class NestedCompleter
{
    public function __construct(private readonly ShopifyClient $client) {}

    /** The same completer on another client (e.g. the short-timeout one of a web request). */
    public function usingClient(ShopifyClient $client): self
    {
        return new self($client);
    }

    public function complete(string $resource, array $node): array
    {
        $key = SyncQueries::nestedKey($resource);
        $connection = $node[$key] ?? null;

        if (! is_array($connection)) {
            return $node;
        }

        $items = Payload::list($connection);
        $pageInfo = $connection['pageInfo'] ?? [];

        while (($pageInfo['hasNextPage'] ?? false) && is_string($pageInfo['endCursor'] ?? null)) {
            $data = $this->client->query(SyncQueries::nestedPage($resource), [
                'id' => (string) ($node['id'] ?? ''),
                'cursor' => $pageInfo['endCursor'],
            ]);
            $more = $data['node'][$key] ?? null;

            if (! is_array($more)) {
                throw new RuntimeException("Shopify returned no further {$key} for ".($node['id'] ?? '?').'.');
            }

            array_push($items, ...Payload::list($more));
            $pageInfo = $more['pageInfo'] ?? [];
        }

        $node[$key] = ['nodes' => $items];

        return $node;
    }
}
