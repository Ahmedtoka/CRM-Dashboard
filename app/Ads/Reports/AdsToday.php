<?php

namespace App\Ads\Reports;

use App\Ads\AdsSettings;
use App\Ads\Control\AdWriteService;
use App\Ads\Control\StopAdvisor;
use App\Ads\Decisions\PendingApprovals;
use App\Models\AdAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * «النهارده» (U 2.3): decisions block, money today vs usual by this hour, the last 7 complete days (D9) with real ROAS
 * first (D10), the buyers strip (managers) and best/worst 5. Cached 60 s per user and filter (spec 8).
 */
final class AdsToday
{
    public const CACHE_SECONDS = 60;

    public const TOP = 5;

    public function __construct(
        private readonly AdsOverview $overview,
        private readonly RunningCreatives $creatives,
        private readonly AdRowEnricher $enricher,
        private readonly SpendByHour $byHour,
        private readonly AdsQuery $q,
        private readonly StopAdvisor $advisor,
        private readonly BuyerScorecard $buyers,
        private readonly AdsSettings $settings,
        private readonly PendingApprovals $approvals,
        private readonly AdWriteService $writes,
    ) {}

    public function build(AdsFilter $week, User $u): array
    {
        $key = 'ads:today:'.$u->id.':'.md5(serialize([$week->fromDate(), $week->toDate(), $week->platform, $week->buyerId, $week->accountIds, $week->restrictBuyerId]));

        return Cache::remember($key, self::CACHE_SECONDS, fn () => $this->fresh($week, $u));
    }

    private function fresh(AdsFilter $week, User $u): array
    {
        $today = CarbonImmutable::now(AdsFilter::TIMEZONE)->startOfDay();
        $todayF = $week->with(['from' => $today, 'to' => $today]);
        $suggestions = $this->advisor->suggest($week->with(['from' => $today->subDays(13), 'to' => $today]));
        $can = $suggestions === [] ? [] : $this->writes->canWriteMany($u, AdAccount::query()
            ->whereIn('id', array_unique(array_column($suggestions, 'account_id')))->get(['id', 'is_active', 'write_enabled', 'platform', 'external_id']));
        $suggestions = array_map(fn (array $x) => $x + ['can_write' => $can[$x['account_id']] ?? false], $suggestions);
        $overview = $this->overview->build($week);

        $thr = $this->settings->winnerThresholds();
        $rows = collect($this->creatives->build($week, ['status' => 'active', 'sort' => 'spend', 'per_page' => 100])['data']);
        $best = $rows->filter(fn (array $r) => $r['real_roas'] !== null && $r['real_roas'] >= (float) $thr['winner'])
            ->sortByDesc('real_roas')->take(self::TOP)->values();
        $worst = $rows->filter(fn (array $r) => $r['spend_today'] > 0 && ($r['real_roas'] ?? 0.0) < (float) $thr['loser'])
            ->sortByDesc('spend_today')->take(self::TOP)->values();
        $enriched = collect($this->enricher->enrich($best->concat($worst)->unique('id')->values()->all(), $week, $u))->keyBy('id');
        $pick = fn ($list) => $list->map(fn (array $r) => $enriched[$r['id']])->values()->all();

        return [
            'decisions' => [
                'approvals' => $this->approvals->count($u),
                'suggestions' => array_slice($suggestions, 0, self::TOP),
                'suggestions_total' => count($suggestions),
                'alerts' => [], // S5
            ],
            'money_today' => $this->byHour->today($week) + [
                'conversations' => $this->q->conversations($todayF->allSpend())->count(),
                'orders' => $this->q->orders($todayF->allSpend())->count(),
            ],
            'last7' => [
                'from' => $week->fromDate(),
                'to' => $week->toDate(),
                'totals' => $overview['totals'],
                'daily' => $overview['daily'],
            ],
            'buyers' => $u->isSupervisorOrAbove() ? $this->buyersStrip($week, $suggestions) : null,
            'best' => $pick($best),
            'worst' => $pick($worst),
        ];
    }

    /** Buyer cards with the open stop suggestions on the accounts they hold. */
    private function buyersStrip(AdsFilter $week, array $suggestions): array
    {
        $byAccount = collect($suggestions)->countBy('account_id');

        return array_map(fn (array $card) => $card + [
            'open_decisions' => collect($card['accounts'])->sum(fn (array $a) => (int) ($byAccount[$a['id']] ?? 0)),
        ], $this->buyers->build($week));
    }
}
