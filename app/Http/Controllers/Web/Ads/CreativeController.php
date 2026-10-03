<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\RunningCreatives;
use App\Ads\Reports\WinnerScorer;
use App\Http\Controllers\Concerns\BuildsAdsPages;
use App\Http\Controllers\Controller;
use App\Models\Ad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreativeController extends Controller
{
    use BuildsAdsPages;

    public function index(Request $request, RunningCreatives $creatives): Response
    {
        $filter = AdsFilter::fromRequest($request, $request->user());
        $status = $this->oneOf($request->query('status'), ['all', 'active', 'inactive'], 'all');
        $sort = $this->oneOf($request->query('sort'), RunningCreatives::SORTS, 'spend');
        $perPage = (int) $this->oneOf((int) $request->query('per_page'), RunningCreatives::PER_PAGE, 25);
        $account = is_numeric($request->query('account')) ? (int) $request->query('account') : null;
        $q = is_string($request->query('q')) && trim($request->query('q')) !== '' ? trim($request->query('q')) : null;
        $page = max((int) $request->query('page', 1), 1);

        $result = $creatives->build($filter, [
            'status' => $status, 'account' => $account, 'platform' => $filter->platform, 'buyer' => $filter->buyerId,
            'q' => $q, 'sort' => $sort, 'per_page' => $perPage, 'page' => $page,
        ]);

        return Inertia::render('Ads/Creatives', [
            'filters' => $this->filterProps($filter) + [
                'status' => $status, 'account' => $account, 'sort' => $sort, 'per_page' => $perPage, 'q' => $q,
                'page' => $result['meta']['current_page'],
            ],
            ...$this->commonProps($request->user(), $filter),
            'result' => $result,
        ]);
    }

    /** One creative with its range numbers and preview markup, for the modal. */
    public function show(Request $request, Ad $ad, RunningCreatives $creatives): JsonResponse
    {
        $filter = AdsFilter::fromRequest($request, $request->user());
        // Out of scope reads as not found: a buyer never learns that other accounts' ads exist.
        abort_if($filter->accountIds !== null && ! in_array($ad->ad_account_id, $filter->accountIds, true), 404);

        return response()->json($creatives->detail($ad, $filter));
    }

    /** Tier chips of the Winners page; `top` (winner + promising) is the default, as on the owner's Arena. */
    public const WINNER_TIERS = ['top', 'all', 'winner', 'promising', 'neutral', 'loser'];

    public const WINNERS_PER_PAGE = 20;

    public function winners(Request $request, WinnerScorer $scorer): Response
    {
        $filter = AdsFilter::fromRequest($request, $request->user());
        $status = $this->oneOf($request->query('status'), ['all', 'active', 'inactive'], 'all');
        $sort = $this->oneOf($request->query('sort'), WinnerScorer::SORTS, 'score');
        $tier = $this->oneOf($request->query('tier'), self::WINNER_TIERS, 'top');
        $window = $scorer->window($filter);

        $all = collect($scorer->build($filter, $status, $sort));
        $byTier = $all->countBy('tier');
        $counts = ['top' => ($byTier['winner'] ?? 0) + ($byTier['promising'] ?? 0), 'all' => $all->count()];
        foreach (['winner', 'promising', 'neutral', 'loser'] as $t) {
            $counts[$t] = $byTier[$t] ?? 0;
        }

        $rows = match ($tier) {
            'all' => $all,
            'top' => $all->whereIn('tier', ['winner', 'promising']),
            default => $all->where('tier', $tier),
        };
        $total = $rows->count();
        $lastPage = max(1, (int) ceil($total / self::WINNERS_PER_PAGE));
        $page = min(max(1, (int) $request->query('page', 1)), $lastPage);

        return Inertia::render('Ads/Winners', [
            'filters' => $this->filterProps($filter) + ['status' => $status, 'sort' => $sort, 'tier' => $tier, 'page' => $page],
            ...$this->commonProps($request->user(), $filter),
            'window' => ['from' => $window->fromDate(), 'to' => $window->toDate()],
            'winners' => $rows->slice(($page - 1) * self::WINNERS_PER_PAGE, self::WINNERS_PER_PAGE)->values()->all(),
            'meta' => ['total' => $total, 'per_page' => self::WINNERS_PER_PAGE, 'current_page' => $page, 'last_page' => $lastPage],
            'tier_counts' => $counts,
        ]);
    }

    /** @param list<string|int> $allowed */
    private function oneOf(mixed $value, array $allowed, string|int $default): string|int
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }
}
