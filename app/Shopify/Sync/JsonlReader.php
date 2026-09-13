<?php

namespace App\Shopify\Sync;

use Generator;
use RuntimeException;

/**
 * Streams a Shopify bulk-operation JSONL file (spec §4.1).
 *
 * Bulk output flattens nested connections: every child object (variant, address,
 * line item, ...) is its own line carrying `__parentId`, and always follows its
 * parent. The reader keeps only the current parent in memory, regroups its
 * children under the GraphQL connection key the mappers' GraphQL normalizers
 * read (`variants: {nodes: [...]}`, `lineItems: {nodes: [...]}`, ...) and yields
 * the parent as soon as the next top-level line starts.
 */
final class JsonlReader
{
    /** Child `__typename` => connection key on the parent node. */
    private const CONNECTION_KEYS = [
        'ProductVariant' => 'variants',
        'MailingAddress' => 'addresses',
        'LineItem' => 'lineItems',
        'Fulfillment' => 'fulfillments',
        'Refund' => 'refunds',
        'Metafield' => 'metafields',
    ];

    /**
     * @return Generator<int, array{0: array<string, mixed>, 1: array<string, list<array<string, mixed>>>}>
     *                                                                                                        parent row (children merged under connection keys) and its children grouped by __typename
     */
    public function objects(string $path): Generator
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Cannot open bulk JSONL file: {$path}");
        }

        try {
            $parent = null;
            $children = [];
            $grandchildren = [];
            $childIds = [];

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $row = json_decode($line, true);

                if (! is_array($row)) {
                    continue;
                }

                $parentId = $row['__parentId'] ?? null;

                if ($parentId === null) {
                    if ($parent !== null) {
                        yield $this->assemble($parent, $children, $grandchildren);
                    }

                    $parent = $row;
                    $children = [];
                    $grandchildren = [];
                    $childIds = [];

                    continue;
                }

                unset($row['__parentId']);
                $type = $this->typename($row);

                if ($parent !== null && $parentId === ($parent['id'] ?? null)) {
                    $children[$type][] = $row;

                    if (is_string($row['id'] ?? null)) {
                        $childIds[$row['id']] = true;
                    }
                } elseif (isset($childIds[$parentId])) {
                    // Second nesting level (e.g. a connection under a line item).
                    $grandchildren[$parentId][$type][] = $row;
                }
                // Anything else is an orphan (its parent was not the preceding top-level line): ignored.
            }

            if ($parent !== null) {
                yield $this->assemble($parent, $children, $grandchildren);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $children
     * @param  array<string, array<string, list<array<string, mixed>>>>  $grandchildren
     * @return array{0: array<string, mixed>, 1: array<string, list<array<string, mixed>>>}
     */
    private function assemble(array $parent, array $children, array $grandchildren): array
    {
        foreach ($children as $type => $rows) {
            foreach ($rows as $i => $child) {
                foreach ($grandchildren[$child['id'] ?? ''] ?? [] as $grandType => $grandRows) {
                    $children[$type][$i][$this->connectionKey($grandType)] = ['nodes' => $grandRows];
                }
            }

            $parent[$this->connectionKey($type)] = ['nodes' => $children[$type]];
        }

        return [$parent, $children];
    }

    private function typename(array $row): string
    {
        if (is_string($row['__typename'] ?? null) && $row['__typename'] !== '') {
            return $row['__typename'];
        }

        if (is_string($row['id'] ?? null) && preg_match('~^gid://shopify/([A-Za-z]+)/~', $row['id'], $m) === 1) {
            return $m[1];
        }

        return 'Unknown';
    }

    private function connectionKey(string $type): string
    {
        return self::CONNECTION_KEYS[$type] ?? lcfirst($type).'s';
    }
}
