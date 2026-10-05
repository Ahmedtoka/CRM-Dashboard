<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsOverview;
use App\Ads\Reports\RevenueSummary;
use App\Ads\Reports\TopAccounts;
use App\Http\Controllers\Concerns\BuildsAdsPages;
use App\Http\Controllers\Controller;
use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class OverviewController extends Controller
{
    use BuildsAdsPages;

    public function __invoke(Request $request, AdsOverview $overview, TopAccounts $topAccounts, RevenueSummary $summary): Response
    {
        $user = $request->user();
        $filter = AdsFilter::fromRequest($request, $user);

        return Inertia::render('Ads/Overview', [
            'filters' => $this->filterProps($filter),
            ...$this->commonProps($user, $filter),
            'overview' => $overview->build($filter),
            'summary' => $summary->build($filter, $user->isSupervisorOrAbove()),
            'top_accounts' => $topAccounts->build($filter),
            'sync' => $this->sync($filter, $user->isSupervisorOrAbove()),
        ]);
    }

    /** @return array{last_synced_at:?string, oldest:?array{account:string,last_synced_at:?string}, errors:list<array{account:string,error:string}>} */
    private function sync(AdsFilter $filter, bool $withErrors): array
    {
        $active = fn () => AdAccount::query()->where('is_active', true)
            ->when($filter->accountIds !== null, fn ($q) => $q->whereIn('id', $filter->accountIds));
        $last = $active()->max('last_synced_at');

        // One fresh account must not hide a stale one: name the account that was synced longest ago (never synced first).
        $oldest = $active()->orderByRaw('last_synced_at IS NOT NULL')->orderBy('last_synced_at')->orderBy('id')->first(['id', 'name', 'last_synced_at']);

        // Connection errors carry platform text and are for the people who manage the connections.
        $errors = ! $withErrors ? [] : AdPlatformConnection::query()
            ->where('status', 'error')->orderBy('id')->get(['id', 'name', 'last_error'])
            ->map(fn (AdPlatformConnection $c) => ['account' => $c->name, 'error' => (string) $c->last_error])->all();

        return [
            'last_synced_at' => $last === null ? null : Carbon::parse($last, 'UTC')->toIso8601String(),
            'oldest' => $oldest === null ? null : [
                'account' => (string) $oldest->name,
                'last_synced_at' => $oldest->last_synced_at === null ? null : Carbon::parse($oldest->last_synced_at, 'UTC')->toIso8601String(),
            ],
            'errors' => $errors,
        ];
    }
}
