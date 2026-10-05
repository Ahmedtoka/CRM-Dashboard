<?php

namespace App\Ads\Health;

/** One evaluated check. key = "{reason}" or "{reason}:{subject}" (an ad account id or a queue name). */
final class HealthCheck
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const CRITICAL = 'critical';

    /** @param  array<string, mixed>  $detail */
    public function __construct(
        public readonly string $key,
        public readonly string $status,
        public readonly array $detail = [],
    ) {}

    /** stale | reconnect | read_only | stuck_runs | control_gap | usage | scheduler | queue | assignments_overlap | link_rate */
    public function reason(): string
    {
        return explode(':', $this->key, 2)[0];
    }

    public function accountId(): ?int
    {
        $id = $this->detail['account_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public function isBad(): bool
    {
        return $this->status !== self::OK;
    }

    /** Severity for sorting: higher is worse. */
    public static function rank(string $status): int
    {
        return match ($status) {
            self::CRITICAL => 2,
            self::WARN => 1,
            default => 0,
        };
    }
}
