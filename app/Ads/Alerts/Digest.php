<?php

namespace App\Ads\Alerts;

use App\Ads\Access\AdsScope;
use App\Ads\Launch\LaunchCounters;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsQuery;
use App\Models\AdAccount;
use App\Models\AdsAlert;
use App\Models\AdWriteAction;
use App\Models\MediaBuyer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The 09:00 digest card (D14, spec 7.5, R 5, U 5.3) of /ads and /today: owner variant (supervisor+ or Ads authority:
 * yesterday's numbers, money leaks, winners, stock, inbox, stale accounts, approvals, per-buyer table) and buyer variant
 * (own numbers and list). Read-only, cached 60 s per user.
 */
final class Digest
{
    public const CACHE_SECONDS = 60;

    public const OWNER_TOP = 5;

    public const BUYER_TOP = 7;

    public const WINNERS = 3;

    public const SILENT_HOURS = 48;

    public const USUAL_DAYS = 14;

    public const STOCK_RULES = ['all.out_of_stock', 'all.sizes_broken', 'all.product_unavailable'];

    public function __construct(
        private readonly AlertScope $scope,
        private readonly AdsScope $adsScope,
        private readonly AdsQuery $q,
        private readonly BreakEven $breakEven,
        private readonly RuleSettings $settings,
        private readonly AlertData $data,
        private readonly Gates $gates,
    ) {}

    /** @return array<string, mixed> */
    public function forUser(User $u, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now(AdsFilter::TIMEZONE))->setTimezone(AdsFilter::TIMEZONE);

        return Cache::remember("ads:digest:{$u->id}:".$now->format('Y-m-d-H-i'), self::CACHE_SECONDS, fn () => $this->build($u, $now));
    }

    /** @return array<string, mixed> */
    private function build(User $u, CarbonImmutable $now): array
    {
        $owner = $u->isSupervisorOrAbove() || $u->hasAdsAuthority();
        $today = $now->startOfDay();
        $yesterday = $today->subDay();
        $buyerId = $owner ? null : ($this->adsScope->buyerFor($u)?->id ?? 0);
        $accountIds = $owner ? null : ($this->adsScope->accountIds($u, $yesterday->subDays(self::USUAL_DAYS), $today) ?? []);

        $open = $this->scope->visible($u)->with(['ad:id,name', 'account:id,name', 'buyer:id,name'])->where('state', AdsAlert::OPEN)->get();
        $problems = $open->where('severity', '!=', Severity::INFO);
        $topSource = $owner ? $problems->where('action', 'stop') : $problems;
        $top = $topSource->sort(fn (AdsAlert $a, AdsAlert $b) => [Severity::rank($b->severity), (float) $b->money_at_risk_per_day]
            <=> [Severity::rank($a->severity), (float) $a->money_at_risk_per_day])
            ->take($owner ? self::OWNER_TOP : self::BUYER_TOP)->values();
        $stock = $open->whereIn('rule_id', self::STOCK_RULES);
        $counters = LaunchCounters::for($u);

        return [
            'variant' => $owner ? 'owner' : 'buyer',
            'date' => $today->toDateString(),
            'shadow' => ! $this->settings->notifyEnabled(),
            'currency' => 'EGP',
            'yesterday' => $this->yesterday($yesterday, $accountIds, $buyerId),
            'open' => [
                'count' => $problems->count(), 'critical' => $problems->where('severity', Severity::CRITICAL)->count(),
                'high' => $problems->where('severity', Severity::HIGH)->count(), 'money' => round((float) $problems->sum('money_at_risk_per_day')),
            ],
            'top' => $top->map(fn (AdsAlert $a) => $this->item($a, $now))->all(),
            'winners' => $owner ? $open->where('rule_id', 'rec.scale_winner')->take(self::WINNERS)->map(fn (AdsAlert $a) => $this->item($a, $now))->values()->all() : [],
            'stock' => [
                'ads' => $stock->pluck('ad_id')->filter()->unique()->count(),
                'products' => $stock->map(fn (AdsAlert $a) => $a->params['product'] ?? null)->filter()->unique()->take(5)->values()->all(),
            ],
            'inbox' => $owner ? $open->firstWhere('rule_id', 'msg.inbox_slow_for_ads')?->params : null,
            'stale_accounts' => $owner ? $this->staleAccounts($now) : 0,
            'approvals' => $owner ? (int) ($counters['awaiting_approval'] ?? 0) : 0,
            'review_waiting' => $owner ? 0 : (int) ($counters['buyer_review'] ?? 0),
            'buyers' => $owner ? $this->buyers($now) : [],
            'stopped_yesterday' => $this->stoppedYesterday($u, $owner, $yesterday),
        ];
    }

    /**
     * Yesterday in the user's scope, every campaign (all-spend totals): spend with tax, real orders and revenue, real
     * ROAS against the spend-weighted floor, and the usual spend (median of the 14 days before).
     *
     * @param  list<int>|null  $accountIds
     * @return array<string, mixed>
     */
    private function yesterday(CarbonImmutable $day, ?array $accountIds, ?int $buyerId): array
    {
        $f = new AdsFilter($day, $day, null, null, $accountIds, $buyerId, false, false);
        $sum = $this->q->sums($f)->first();
        $d = $this->q->deriveWithControl($f, $sum ?? ['spend' => 0]);
        $orders = $this->q->orders($f);
        $revenue = round((float) $orders->sum('net'), 2);
        $history = new AdsFilter($day->subDays(self::USUAL_DAYS), $day->subDay(), null, null, $accountIds, $buyerId, false, false);
        $daily = $this->q->sums($history, ['d' => 'm.date'])->map(fn (object $r) => (float) $r->spend)->values()->all();
        $usual = Stats::median($daily);
        [$floor, $default] = $this->floor($accountIds, $day);

        return [
            'spend' => (float) ($d['spend'] ?? 0), 'spend_tax' => (float) ($d['spend_tax'] ?? 0), 'orders' => $orders->count(), 'revenue' => $revenue,
            'roas' => AdsQuery::ratio($revenue, (float) ($d['spend'] ?? 0), 2), 'meta_roas' => $d['roas'] ?? null,
            'usual_spend' => $usual === null ? null : round($usual, 2), 'floor' => $floor, 'floor_default' => $default,
        ];
    }

    /**
     * Spend-weighted break-even of the accounts that spent on the day; the default flag when any of them is on the default.
     *
     * @param  list<int>|null  $accountIds
     * @return array{0: float, 1: bool}
     */
    private function floor(?array $accountIds, CarbonImmutable $day): array
    {
        $spend = DB::table('ad_daily_metrics')
            ->where('date', '>=', $day->toDateString())->where('date', '<=', $day->toDateString().' 23:59:59')
            ->when($accountIds !== null, fn ($q) => $q->whereIn('ad_account_id', $accountIds === [] ? [0] : $accountIds))
            ->groupBy('ad_account_id')->selectRaw('ad_account_id, SUM(spend) as s')->pluck('s', 'ad_account_id');
        $total = (float) $spend->sum();
        if ($total <= 0) {
            return [BreakEven::DEFAULT_FLOOR, true];
        }
        $weighted = 0.0;
        $default = false;
        foreach ($spend as $id => $s) {
            $b = $this->breakEven->forAccount((int) $id);
            $weighted += $b['floor'] * (float) $s;
            $default = $default || $b['is_default'];
        }

        return [round($weighted / $total, 2), $default];
    }

    private function staleAccounts(CarbonImmutable $now): int
    {
        return AdAccount::query()->where('is_active', true)->get()
            ->filter(fn (AdAccount $a) => ! $this->gates->dataFresh($a, $now, $this->data)['ok'])->count();
    }

    /** @return list<array<string, mixed>> */
    private function buyers(CarbonImmutable $now): array
    {
        [$from, $to] = AlertData::utcRange($now->subDay()->toDateString(), $now->subDay()->toDateString());
        $silentBefore = $now->subHours(self::SILENT_HOURS)->utc();

        return MediaBuyer::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->map(function (MediaBuyer $b) use ($from, $to, $silentBefore) {
                $q = fn () => AdsAlert::query()->where('buyer_id', $b->id);
                $open = fn () => $q()->where('state', AdsAlert::OPEN)->where('severity', '!=', Severity::INFO);

                return [
                    'buyer_id' => $b->id, 'name' => (string) $b->name,
                    'open' => $open()->count(),
                    'acted' => $q()->where('state', AdsAlert::ACTED)->whereBetween('closed_at', [$from, $to])->count(),
                    'dismissed' => $q()->where('state', AdsAlert::DISMISSED)->whereBetween('closed_at', [$from, $to])->count(),
                    'dismissed_wrong_numbers' => $q()->where('state', AdsAlert::DISMISSED)->where('dismiss_reason', 'wrong_numbers')->whereBetween('closed_at', [$from, $to])->count(),
                    'silent_spend' => round((float) $open()->where('first_fired_at', '<', $silentBefore)->sum('money_at_risk_per_day'), 2),
                ];
            })->values()->all();
    }

    private function stoppedYesterday(User $u, bool $owner, CarbonImmutable $day): int
    {
        [$from, $to] = AlertData::utcRange($day->toDateString(), $day->toDateString());

        return AdWriteAction::query()->where('state', AdWriteAction::SUCCEEDED)->where('to_status', 'paused')
            ->whereBetween('confirmed_at', [$from, $to])
            ->when(! $owner, fn ($q) => $q->where('confirmed_by_id', $u->id))->count();
    }

    /** @return array<string, mixed> */
    private function item(AdsAlert $a, CarbonImmutable $now): array
    {
        return [
            'alert_id' => $a->id, 'rule_id' => $a->rule_id, 'severity' => $a->severity, 'sentence_key' => $a->sentence_key,
            'params' => $a->params ?? [], 'ad' => $a->ad?->name, 'account' => $a->account?->name, 'buyer' => $a->buyer?->name,
            'money' => round((float) $a->money_at_risk_per_day),
            'age_days' => $a->first_fired_at !== null ? (int) floor($a->first_fired_at->diffInDays($now, true)) : 0,
        ];
    }
}
