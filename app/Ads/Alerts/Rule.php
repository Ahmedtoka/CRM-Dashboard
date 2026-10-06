<?php

namespace App\Ads\Alerts;

use Carbon\CarbonImmutable;

/** One decision rule (R section 2). Reads only the DB through RuleContext; never writes to an ad platform. */
interface Rule
{
    public const HOURLY = 'hourly';

    public const DAILY = 'daily';

    public function id(): string;

    /** hourly rules also run in the daily evaluation; daily rules only there. */
    public function schedule(): string;

    /** Performance rules judge ads on ads data: skipped for an account whose data is stale. */
    public function isPerformance(): bool;

    /** When a closed row of this rule may fire again for the same entity. */
    public function cooldownUntil(CarbonImmutable $now): CarbonImmutable;

    /** @return list<Finding> */
    public function evaluate(RuleContext $ctx): array;
}
