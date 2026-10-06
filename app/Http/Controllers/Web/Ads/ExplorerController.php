<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\AdsSettings;
use App\Ads\Reports\AdHealth;
use App\Ads\Reports\AdInsights;
use App\Ads\Reports\AdRowEnricher;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsQuery;
use App\Ads\Reports\CampaignTree;
use App\Ads\Reports\Objective;
use App\Ads\Reports\RunningCreatives;
use App\Ads\Reports\WinnerScorer;
use App\Http\Controllers\Concerns\BuildsAdsPages;
use App\Http\Controllers\Controller;
use App\Models\AdWriteAction;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** «الإعلانات» (U 2.1, spec 4.1): Creatives + Winners + Campaigns as one explorer with three views. */
class ExplorerController extends Controller
{
    use BuildsAdsPages;

    public const VIEWS = ['table', 'cards', 'tree'];

    /** URL status => RunningCreatives status. */
    public const STATUSES = ['running' => 'active', 'paused' => 'inactive', 'all' => 'all'];

    /** `top` = winner + promising (the old Winners default), `promising` / `neutral` = the old tier chips. */
    public const HEALTH = ['no_result', 'losing', 'tired', 'winning', 'out_of_stock', 'top', 'promising', 'neutral'];

    /** WinnerScorer tiers behind each tier-based health filter. */
    public const HEALTH_TIERS = ['losing' => ['loser'], 'winning' => ['winner'], 'top' => ['winner', 'promising'], 'promising' => ['promising'], 'neutral' => ['neutral']];

    public const PER_PAGE = [25, 50, 100];

    public function __construct(
        private readonly WinnerScorer $scorer,
        private readonly AdHealth $health,
        private readonly AdInsights $insights,
        private readonly AdsQuery $q,
        private readonly AdsSettings $settings,
    ) {}

    public function __invoke(Request $request, RunningCreatives $creatives, AdRowEnricher $enricher, CampaignTree $tree): Response
    {
        $user = $request->user();
        $filter = AdsFilter::fromRequest($request, $user, 'last7');
        $view = self::oneOf($request->query('view'), self::VIEWS, 'table');
        $status = self::oneOf($request->query('status'), array_keys(self::STATUSES), 'running');
        $objective = self::oneOf($request->query('objective'), Objective::FAMILIES, null);
        $health = self::oneOf($request->query('health'), self::HEALTH, null);
        $changed = $request->query('changed') === 'today' ? 'today' : null;
        $search = is_string($request->query('q')) && trim($request->query('q')) !== '' ? mb_substr(trim($request->query('q')), 0, 100) : null;
        [$sortKey, $dir] = self::sort($request->query('sort'));
        $perPage = in_array((int) $request->query('per_page'), self::PER_PAGE, true) ? (int) $request->query('per_page') : 25;

        $props = [
            'filters' => $this->filterProps($filter, $request) + [
                'view' => $view, 'status' => $status, 'objective' => $objective, 'health' => $health, 'changed' => $changed,
                'q' => $search, 'sort' => ($dir === 'asc' ? '' : '-').$sortKey, 'per_page' => $perPage, 'page' => 1,
            ],
            ...$this->commonProps($user, $filter),
            'account_options' => $this->accountOptions($request, $filter),
            'freshness' => $this->syncProps($filter, false)['oldest']['last_synced_at'] ?? null,
            'result' => null,
            'tree' => null,
            'tier_counts' => null, // view=cards only
        ];

        if ($view === 'tree') {
            $props['tree'] = $this->withCanWrite($tree->build($filter, in_array($sortKey, CampaignTree::SORTS, true) ? $sortKey : 'spend'), $request);

            return Inertia::render('Ads/Explorer', $props);
        }

        $opts = [
            'status' => self::STATUSES[$status], 'q' => $search, 'sort' => $sortKey, 'dir' => $dir, 'per_page' => $perPage,
            'page' => max(1, (int) $request->query('page', 1)), 'objective' => $objective,
        ];
        if ($health === 'no_result') {
            $opts['no_result_min'] = (float) $this->settings->winnerThresholds()['loser_min_spend'];
        }
        $only = self::intersect($this->healthIds($health, $filter), $changed === 'today' ? $this->changedToday($filter) : null);
        if ($only !== null) {
            $opts['only_ids'] = $only;
        }

        $result = $creatives->build($filter, $opts);
        if ($view === 'cards') {
            // The old Winners chips: how many scored ads sit in each tier (the gate keeps too-early ads out of `all`).
            $byTier = collect($this->scorer->tiers($filter))->countBy();
            $props['tier_counts'] = ['top' => ($byTier['winner'] ?? 0) + ($byTier['promising'] ?? 0), 'all' => $byTier->sum()]
                + collect(['winner', 'promising', 'neutral', 'loser'])->mapWithKeys(fn (string $t) => [$t => $byTier[$t] ?? 0])->all();
        }
        $result['data'] = $enricher->enrich($result['data'], $filter, $user);
        $props['result'] = $result;
        $props['filters']['page'] = $result['meta']['current_page'];

        return Inertia::render('Ads/Explorer', $props);
    }

    /** @return array{0: string, 1: 'asc'|'desc'} `-spend` = spend desc, `spend` = spend asc; unknown = -spend */
    public static function sort(mixed $raw): array
    {
        $s = is_string($raw) && $raw !== '' ? $raw : '-spend';
        $key = ltrim($s, '-');

        return in_array($key, RunningCreatives::SORTS, true) ? [$key, str_starts_with($s, '-') ? 'desc' : 'asc'] : ['spend', 'desc'];
    }

    /** @return list<int>|null null = no id restriction */
    private function healthIds(?string $health, AdsFilter $f): ?array
    {
        return match ($health) {
            'losing', 'winning', 'top', 'promising', 'neutral' => array_keys(array_filter($this->scorer->tiers($f), fn (string $t) => in_array($t, self::HEALTH_TIERS[$health], true))),
            'out_of_stock' => $this->health->needStopIds($f),
            'tired' => $this->tiredIds($f),
            default => null,
        };
    }

    /** @return list<int> */
    private function tiredIds(AdsFilter $f): array
    {
        $ids = $this->q->sums($f, ['ad_id' => 'm.ad_id'])->pluck('ad_id')->map(fn ($id) => (int) $id)->all();

        return array_keys(array_filter($this->insights->forAds($ids, $f->to), fn (array $i) => $i['fatigue']['flag']));
    }

    /** @return list<int> ads with a succeeded Stop/Run confirmed today (Cairo) in the filter's accounts */
    private function changedToday(AdsFilter $f): array
    {
        $start = CarbonImmutable::now(AdsFilter::TIMEZONE)->startOfDay()->utc();

        return DB::table('ad_write_actions as w')
            ->join('ads as ad', fn ($j) => $j->on('ad.ad_account_id', '=', 'w.ad_account_id')->on('ad.external_id', '=', 'w.target_external_id'))
            ->where('w.target_level', 'ad')->where('w.state', AdWriteAction::SUCCEEDED)->where('w.confirmed_at', '>=', $start)
            ->when($f->accountIds !== null, fn ($q) => $q->whereIn('w.ad_account_id', $f->accountIds))
            ->distinct()->pluck('ad.id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** @param list<int>|null $a @param list<int>|null $b @return list<int>|null */
    private static function intersect(?array $a, ?array $b): ?array
    {
        return match (true) {
            $a === null => $b,
            $b === null => $a,
            default => array_values(array_intersect($a, $b)),
        };
    }

    private static function oneOf(mixed $v, array $allowed, mixed $default): mixed
    {
        return in_array($v, $allowed, true) ? $v : $default;
    }
}
