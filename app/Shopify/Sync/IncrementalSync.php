<?php

namespace App\Shopify\Sync;

use App\Shopify\Client\ShopifyClient;
use App\Shopify\Sync\Mappers\Payload;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Paged `updated_at` window sync for products, customers or orders (spec §4.3
 * nightly reconciliation, §4.5 manual sync). Page sizes keep every request under
 * Shopify's 1000-point query cost (SyncQueries). The mappers' stale guard makes
 * unchanged records no-ops, so overlapping windows are safe.
 */
final class IncrementalSync
{
    public function __construct(
        private readonly ShopifyClient $client,
        private readonly ResourceRowMapper $rows,
        private readonly SyncRunRecorder $recorder,
    ) {}

    public function run(string $resource, CarbonInterface $since, ?CarbonInterface $until = null, string $type = 'manual'): SyncRunSummary
    {
        if (! in_array($resource, SyncQueries::RESOURCES, true)) {
            throw new InvalidArgumentException("Unknown Shopify sync resource [{$resource}].");
        }

        $run = $this->recorder->open($type, $resource, $since, $until);
        $summary = SyncRunSummary::empty();
        $filter = "updated_at:>='".$this->iso($since)."'".($until !== null ? " AND updated_at:<='".$this->iso($until)."'" : '');
        $cursor = null;

        try {
            do {
                $data = $this->client->query(SyncQueries::paged($resource), [
                    'cursor' => $cursor,
                    'query' => $filter,
                ]);
                $page = $data[$resource] ?? null;

                if (! is_array($page)) {
                    break;
                }

                foreach (Payload::list($page) as $node) {
                    try {
                        $summary = $summary->withResult($this->rows->map($resource, $this->completeNested($resource, $node)));
                    } catch (Throwable $e) {
                        $summary = $summary->withFailure();
                        $this->recorder->recordError($run, (string) ($node['id'] ?? '?'), $e->getMessage());
                    }
                }

                $this->recorder->progress($run, $summary);
                $cursor = ($page['pageInfo']['hasNextPage'] ?? false) ? ($page['pageInfo']['endCursor'] ?? null) : null;
            } while ($cursor !== null);
        } catch (Throwable $e) {
            $this->recorder->recordError($run, $resource, $e->getMessage());
            $this->recorder->close($run, $summary, 'failed');

            throw $e;
        }

        $this->recorder->close($run, $summary, 'completed');

        return $summary;
    }

    /**
     * Fetches the remaining pages of the node's nested list (variants, addresses,
     * line items): the mappers delete whatever is missing from it.
     */
    private function completeNested(string $resource, array $node): array
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

    private function iso(CarbonInterface $time): string
    {
        return $time->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
