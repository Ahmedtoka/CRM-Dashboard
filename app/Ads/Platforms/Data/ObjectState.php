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
}
