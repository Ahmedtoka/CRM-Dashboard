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
 *
 * Each parent is yielded with the byte offset just past it (the start of the
 * next top-level line, or EOF): a parent boundary that `objects($path, $offset)`
 * can resume from. A malformed line is yielded as `['__malformed' => message]`
 * with a null offset, immediately before the parent it was read inside; it is
 * never a resume point.
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
     * @param  int  $offset  a parent boundary previously yielded by this reader (0 = start)
     * @return Generator<int, array{0: array<string, mixed>, 1: array<string, list<array<string, mixed>>>, 2: int|null}>
     *                                                                                                                   parent row (children merged under connection keys), its children grouped by __typename, and the resume offset after it
     */
    public function objects(string $path, int $offset = 0): Generator
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Cannot open bulk JSONL file: {$path}");
        }

        try {
            if ($offset > 0 && fseek($handle, $offset) !== 0) {
                throw new RuntimeException("Cannot seek bulk JSONL file {$path} to byte {$offset}.");
            }

            $parent = null;
            $children = [];
            $grandchildren = [];
            $childIds = [];
            $malformed = [];

            while (true) {
                $lineStart = ftell($handle);
                $line = fgets($handle);

                if ($line === false) {
                    break;
                }

                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $row = json_decode($line, true);

                if (! is_array($row)) {
                    $malformed[] = $lineStart;

                    continue;
                }

                $parentId = $row['__parentId'] ?? null;

                if ($parentId === null) {
                    yield from $this->malformed($malformed);
                    $malformed = [];

                    if ($parent !== null) {
                        [$assembled, $grouped] = $this->assemble($parent, $children, $grandchildren);

                        yield [$assembled, $grouped, $lineStart];
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

            $end = ftell($handle);
            yield from $this->malformed($malformed);

            if ($parent !== null) {
                [$assembled, $grouped] = $this->assemble($parent, $children, $grandchildren);

                yield [$assembled, $grouped, $end];
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<int>  $positions
     * @return Generator<int, array{0: array{__malformed: string}, 1: array{}, 2: null}>
     */
    private function malformed(array $positions): Generator
    {
        foreach ($positions as $position) {
            yield [['__malformed' => "Malformed JSONL line at byte {$position}."], [], null];
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
