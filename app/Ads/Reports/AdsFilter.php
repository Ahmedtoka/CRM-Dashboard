<?php

namespace App\Ads\Reports;

use App\Ads\Access\AdsScope;
use App\Ads\Platforms\AdPlatform;
use App\Ads\Sync\HistoryWindow;
use App\Enums\UserRole;
use App\Models\MediaBuyer;
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

    public const MAX_DAYS = 366;

    /** @param  list<int>|null  $accountIds */
    public function __construct(
        CarbonImmutable $from,
        CarbonImmutable $to,
        public ?string $platform = null,
        public ?int $buyerId = null,
        public ?array $accountIds = null,
        public ?int $restrictBuyerId = null,
        public bool $clampedToHistory = false,
        public bool $activeCampaignsOnly = false,
    ) {
        $f = CarbonImmutable::parse($from->toDateString(), self::TIMEZONE)->startOfDay();
        $t = CarbonImmutable::parse($to->toDateString(), self::TIMEZONE)->startOfDay();
        [$this->from, $this->to] = $f->greaterThan($t) ? [$t, $f] : [$f, $t];
    }

    public const RANGES = ['today', 'yesterday', 'last7', 'last30', 'this_month'];

    /**
     * The page's default range (D9: Today/Explorer `last7`, Numbers `this_month`), overridden by `range=` or by
     * `from`/`to`. Applies the user's AdsScope. `buyer=me` = the viewer's own buyer row (0 = nothing).
     */
    public static function fromRequest(Request $r, User $u, string $default = 'last30'): self
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $preset = in_array($r->query('range'), self::RANGES, true) ? (string) $r->query('range') : null;
        $from = self::date($r->query('from'));
        $to = self::date($r->query('to'));
        if ($preset !== null || ($from === null && $to === null)) {
            [$from, $to] = self::preset($preset ?? $default, $today);
        } else {
            $to ??= $today;
            $from ??= $to->subDays(29);
        }
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }
        // At most MAX_DAYS: a hand-typed `from=2000-01-01` would load every attributed order and
        // conversation into PHP and build a daily series of thousands of days.
        if ($from->lessThan($to->subDays(self::MAX_DAYS - 1))) {
            $from = $to->subDays(self::MAX_DAYS - 1);
        }
        // Nothing before crm.ads.history_start is kept, so a report never starts earlier.
        $start = HistoryWindow::start();
        $clamped = $from->lessThan($start);
        if ($clamped) {
            $from = $start;
            if ($to->lessThan($from)) {
                $to = $from;
            }
        }

        $platform = AdPlatform::tryFrom((string) $r->query('platform', ''))?->value;
        $scope = app(AdsScope::class);
        $allowed = $scope->accountIds($u, $from, $to);

        $requested = self::accountIdsFrom($r->query('accounts'));
        $accountIds = match (true) {
            $allowed === null => $requested === [] ? null : $requested,
            $requested === [] => $allowed,
            default => array_values(array_intersect($allowed, $requested)),
        };

        $restrict = null;
        $buyerId = null;
        if ($u->role === UserRole::MediaBuyer) {
            $restrict = $scope->buyerFor($u)?->id ?? 0; // 0 never matches: an unlinked buyer sees nothing
        } elseif ($u->isSupervisorOrAbove() && $r->query('buyer') === 'me') {
            // AdsScope::buyerFor answers media buyers only; a supervisor who also buys is found by user_id.
            $buyerId = (int) (MediaBuyer::query()->where('user_id', $u->id)->value('id') ?? 0);
        } elseif ($u->isSupervisorOrAbove() && is_numeric($r->query('buyer'))) {
            $buyerId = (int) $r->query('buyer');
        }

        return new self($from, $to, $platform, $buyerId, $accountIds, $restrict, $clamped, true);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} `last7` = the last 7 COMPLETE days (D9), today excluded */
    public static function preset(string $key, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $yesterday = $today->subDay();

        return match ($key) {
            'today' => [$today, $today],
            'yesterday' => [$yesterday, $yesterday],
            'last7' => [$yesterday->subDays(6), $yesterday],
            'this_month' => [$today->startOfMonth(), $today],
            default => [$today->subDays(29), $today],
        };
    }

    /** The preset these dates equal today, or null for a custom range. */
    public function rangeKey(): ?string
    {
        foreach (self::RANGES as $key) {
            [$f, $t] = self::preset($key);
            if ($f->equalTo($this->from) && $t->equalTo($this->to)) {
                return $key;
            }
        }

        return null;
    }

    /** @return list<int> `accounts[]=1&accounts[]=2` (old links) or `accounts=1,2` (useUrlFilters) */
    public static function accountIdsFrom(mixed $raw): array
    {
        $list = is_string($raw) ? explode(',', $raw) : (array) ($raw ?? []);

        return collect($list)->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /** @param  array<string, mixed>  $changes */
    public function with(array $changes): self
    {
        $v = array_merge([
            'from' => $this->from, 'to' => $this->to, 'platform' => $this->platform, 'buyerId' => $this->buyerId,
            'accountIds' => $this->accountIds, 'restrictBuyerId' => $this->restrictBuyerId,
            'clampedToHistory' => $this->clampedToHistory, 'activeCampaignsOnly' => $this->activeCampaignsOnly,
        ], $changes);

        return new self($v['from'], $v['to'], $v['platform'], $v['buyerId'], $v['accountIds'], $v['restrictBuyerId'], $v['clampedToHistory'], $v['activeCampaignsOnly']);
    }

    /** The same filter counting every campaign's spend (paused, archived, deleted): used by totals (D1). */
    public function allSpend(): self
    {
        return $this->activeCampaignsOnly ? $this->with(['activeCampaignsOnly' => false]) : $this;
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
