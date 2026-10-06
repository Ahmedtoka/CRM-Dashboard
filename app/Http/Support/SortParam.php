<?php

namespace App\Http\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * The `sort` query param of a list page: `-key` descending, `key` ascending, only whitelisted keys (page column key
 * => SQL column). Anything else is ignored, so a hand-edited URL can never order by an arbitrary column.
 */
final class SortParam
{
    private function __construct(
        public readonly string $key,
        public readonly string $column,
        public readonly string $direction,
    ) {}

    /** @param  array<string, string>  $allowed */
    public static function parse(mixed $raw, array $allowed): ?self
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $descending = str_starts_with($raw, '-');
        $key = $descending ? substr($raw, 1) : $raw;

        if ($key === '' || ! array_key_exists($key, $allowed)) {
            return null;
        }

        return new self($key, $allowed[$key], $descending ? 'desc' : 'asc');
    }

    public function value(): string
    {
        return ($this->direction === 'desc' ? '-' : '').$this->key;
    }

    /** Replaces the query's ordering; the tie-breaker keeps pages stable. */
    public function apply(Builder $query, string $tieBreaker = 'id'): Builder
    {
        return $query->reorder()->orderBy($this->column, $this->direction)->orderBy($tieBreaker, 'desc');
    }
}
