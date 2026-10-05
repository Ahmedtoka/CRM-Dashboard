<?php

namespace App\Ads\Platforms\Data;

use Carbon\CarbonImmutable;

/**
 * A live read of one campaign, ad set or ad (the Run guard and the executor's read-back). Budgets are integers in the
 * account currency's minor unit; null when the object has no such budget. Parents are listed nearest first
 * (an ad: its ad set, then its campaign).
 */
final readonly class ObjectState
{
    /**
     * @param  list<array{level: string, status: ?string, dailyBudgetMinor: ?int, lifetimeBudgetMinor: ?int, endsAt: ?CarbonImmutable}>  $parents
     */
    public function __construct(
        public ?string $status,
        public ?string $effectiveStatus,
        public ?int $dailyBudgetMinor,
        public ?int $lifetimeBudgetMinor,
        public ?CarbonImmutable $endsAt,
        public string $currency,
        public array $parents = [],
    ) {}

    /**
     * A JSON-safe snapshot (stored in ad_write_actions.expected so a fresh propose-time read can be reused at confirm).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'effectiveStatus' => $this->effectiveStatus,
            'dailyBudgetMinor' => $this->dailyBudgetMinor,
            'lifetimeBudgetMinor' => $this->lifetimeBudgetMinor,
            'endsAt' => $this->endsAt?->toIso8601String(),
            'currency' => $this->currency,
            'parents' => array_map(fn (array $p) => array_merge($p, ['endsAt' => $p['endsAt']?->toIso8601String()]), $this->parents),
        ];
    }

    /** @param  array<string, mixed>  $a  a toArray() snapshot */
    public static function fromArray(array $a): self
    {
        $time = fn ($v) => is_string($v) && $v !== '' ? CarbonImmutable::parse($v)->utc() : null;
        $int = fn ($v) => is_numeric($v) ? (int) $v : null;

        return new self(
            status: isset($a['status']) ? (string) $a['status'] : null,
            effectiveStatus: isset($a['effectiveStatus']) ? (string) $a['effectiveStatus'] : null,
            dailyBudgetMinor: $int($a['dailyBudgetMinor'] ?? null),
            lifetimeBudgetMinor: $int($a['lifetimeBudgetMinor'] ?? null),
            endsAt: $time($a['endsAt'] ?? null),
            currency: (string) ($a['currency'] ?? ''),
            parents: array_values(array_map(fn (array $p) => [
                'level' => (string) ($p['level'] ?? ''),
                'status' => isset($p['status']) ? (string) $p['status'] : null,
                'dailyBudgetMinor' => $int($p['dailyBudgetMinor'] ?? null),
                'lifetimeBudgetMinor' => $int($p['lifetimeBudgetMinor'] ?? null),
                'endsAt' => $time($p['endsAt'] ?? null),
            ], is_array($a['parents'] ?? null) ? $a['parents'] : [])),
        );
    }
}
