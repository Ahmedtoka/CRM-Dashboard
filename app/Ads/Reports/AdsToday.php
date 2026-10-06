<?php

namespace App\Ads\Reports;

use App\Ads\AdsSettings;
use App\Ads\Alerts\AlertFeed;
use App\Ads\Control\AdWriteService;
use App\Ads\Control\StopAdvisor;
use App\Ads\Decisions\DecisionCounter;
use App\Models\AdAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * «النهارده» (U 2.3): decisions block (DecisionCounter's one count, the top stop suggestions and alert cards), money
 * today vs usual by this hour, the last 7 complete days (D9) with real ROAS first (D10), the buyers strip (managers) and best/worst 5. Cached 60 s per user and filter (spec 8).
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
        private readonly AdWriteService $writes,
        private readonly DecisionCounter $counter,
        private readonly AlertFeed $feed,
    ) {}

    /** $wholeScope = no accounts, buyer or platform picked: the block's count is then the viewer's badge (stored). */
    public function build(AdsFilter $week, User $u, bool $wholeScope = true): array
    {
        $key = 'ads:today:'.$u->id.':'.md5(serialize([$week->fromDate(), $week->toDate(), $week->platform, $week->buyerId, $week->accountIds, $week->restrictBuyerId, $wholeScope]));

        return Cache::remember($key, self::CACHE_SECONDS, fn () => $this->fresh($week, $u, $wholeScope));
    }

    private function fresh(AdsFilter $week, User $u, bool $wholeScope): array
    {
        $today = CarbonImmutable::now(AdsFilter::TIMEZONE)->startOfDay();
        $todayF = $week->with(['from' => $today, 'to' => $today]);
        $suggestions = $this->advisor->suggest(DecisionCounter::window($week));
        // The one open-decisions count (final fix 8): approvals + open alert cards + suggestions not folded into a card.
        $decisions = $this->counter->breakdown($u, $week, $suggestions);
        if ($wholeScope && DecisionCounter::eligible($u)) {
            DecisionCounter::store($u, $decisions['total']);
        }
        $open = $decisions['suggestions'];
        $can = $open === [] ? [] : $this->writes->canWriteMany($u, AdAccount::query()
            ->whereIn('id', array_unique(array_column($open, 'account_id')))->get(['id', 'is_active', 'write_enabled', 'platform', 'external_id']));
        $open = array_map(fn (array $x) => $x + ['can_write' => $can[$x['account_id']] ?? false], $open);
        $overview = $this->overview->build($week);

        $thr = $this->settings->winnerThresholds();
        $rows = collect($this->creatives->build($week, ['status' => 'active', 'sort' => 'spend', 'per_page' => 100])['data']);
        $best = $rows->filter(fn (array $r) => $r['real_roas'] !== null && $r['real_roas'] >= (float) $thr['winner'])
            ->sortByDesc('real_roas')->take(self::TOP)->values();
        // Spending today and over the range, on an EGP account (real ROAS is null elsewhere, never a loss).
        $worst = $rows->filter(fn (array $r) => $r['spend_today'] > 0 && $r['spend'] > 0 && ($r['currency'] ?? 'EGP') === 'EGP'
            && ($r['real_roas'] ?? 0.0) < (float) $thr['loser'])
            ->sortByDesc('spend_today')->take(self::TOP)->values();
        $enriched = collect($this->enricher->enrich($best->concat($worst)->unique('id')->values()->all(), $week, $u))->keyBy('id');
        $pick = fn ($list) => $list->map(fn (array $r) => $enriched[$r['id']])->values()->all();

        return [
            'decisions' => [
                'total' => $decisions['total'],
                'approvals' => $decisions['approvals'],
                'suggestions' => array_slice($open, 0, self::TOP),
                'suggestions_total' => count($open),
                // The top open S5 alert cards (same cards as «محتاج قرار»), the count before the buyer cap.
                'alerts' => $decisions['alert_cards'] > 0 ? array_slice($this->feed->forUser($u)['items'], 0, self::TOP) : [],
                'alerts_total' => $decisions['alert_cards'],
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
