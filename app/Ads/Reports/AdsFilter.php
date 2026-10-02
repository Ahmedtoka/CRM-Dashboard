<?php

namespace App\Ads\Reports;

use App\Ads\Access\AdsScope;
use App\Ads\Platforms\AdPlatform;
use App\Enums\UserRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Filter shared by every Ads report. Dates are calendar days (the metrics' `date` column, Cairo for
 * timestamps); accountIds null = every account, [] = nothing; restrictBuyerId keeps only metric rows
 * (and orders/conversations) that belonged to that buyer on their day.
 */
final readonly class AdsFilter
{
    public const TIMEZONE = 'Africa/Cairo';

    public CarbonImmutable $from;

    public CarbonImmutable $to;

    /** @param  list<int>|null  $accountIds */
    public function __construct(
        CarbonImmutable $from,
        CarbonImmutable $to,
        public ?string $platform = null,
        public ?int $buyerId = null,
        public ?array $accountIds = null,
        public ?int $restrictBuyerId = null,
    ) {
        $f = CarbonImmutable::parse($from->toDateString(), self::TIMEZONE)->startOfDay();
        $t = CarbonImmutable::parse($to->toDateString(), self::TIMEZONE)->startOfDay();
        [$this->from, $this->to] = $f->greaterThan($t) ? [$t, $f] : [$f, $t];
    }

    /** Defaults to the last 30 days ending today (Cairo) and applies the user's AdsScope. */
    public static function fromRequest(Request $r, User $u): self
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $to = self::date($r->query('to')) ?? $today;
        $from = self::date($r->query('from')) ?? $to->subDays(29);
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $platform = AdPlatform::tryFrom((string) $r->query('platform', ''))?->value;
        $scope = app(AdsScope::class);
        $allowed = $scope->accountIds($u, $from, $to);

        $requested = collect((array) ($r->query('accounts') ?? []))
            ->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (int) $v)->unique()->values()->all();
        $accountIds = match (true) {
            $allowed === null => $requested === [] ? null : $requested,
            $requested === [] => $allowed,
            default => array_values(array_intersect($allowed, $requested)),
        };

        $restrict = null;
        $buyerId = null;
        if ($u->role === UserRole::MediaBuyer) {
            $restrict = $scope->buyerFor($u)?->id ?? 0; // 0 never matches: an unlinked buyer sees nothing
        } elseif ($u->isSupervisorOrAbove() && is_numeric($r->query('buyer'))) {
            $buyerId = (int) $r->query('buyer');
        }

        return new self($from, $to, $platform, $buyerId, $accountIds, $restrict);
    }

    /** @param  array<string, mixed>  $changes */
    public function with(array $changes): self
    {
        $v = array_merge([
            'from' => $this->from, 'to' => $this->to, 'platform' => $this->platform, 'buyerId' => $this->buyerId,
            'accountIds' => $this->accountIds, 'restrictBuyerId' => $this->restrictBuyerId,
        ], $changes);

        return new self($v['from'], $v['to'], $v['platform'], $v['buyerId'], $v['accountIds'], $v['restrictBuyerId']);
    }

    /** True when the scope can never match anything (content users, unlinked buyers). */
    public function isEmpty(): bool
    {
        return $this->accountIds === [] || $this->restrictBuyerId === 0;
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    /** Start of `from` in Cairo, as a UTC instant (timestamps are stored in UTC). */
    public function startUtc(): CarbonImmutable
    {
        return $this->from->startOfDay()->utc();
    }

    public function endUtc(): CarbonImmutable
    {
        return $this->to->endOfDay()->utc();
    }

    /** @return list<string> every day of the range, Y-m-d */
    public function days(): array
    {
        $out = [];
        for ($d = $this->from; $d->lessThanOrEqualTo($this->to); $d = $d->addDay()) {
            $out[] = $d->toDateString();
        }

        return $out;
    }

    private static function date(mixed $v): ?CarbonImmutable
    {
        if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }

        try {
            $d = CarbonImmutable::createFromFormat('!Y-m-d', $v, self::TIMEZONE);
        } catch (\Throwable) {
            return null;
        }

        return $d !== null && $d->format('Y-m-d') === $v ? $d : null;
    }
}
