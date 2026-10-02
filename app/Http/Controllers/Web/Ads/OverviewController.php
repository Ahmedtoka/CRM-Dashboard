<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsOverview;
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

    public function __invoke(Request $request, AdsOverview $overview, TopAccounts $topAccounts): Response
    {
        $user = $request->user();
        $filter = AdsFilter::fromRequest($request, $user);

        return Inertia::render('Ads/Overview', [
            'filters' => $this->filterProps($filter),
            ...$this->commonProps($user, $filter),
            'overview' => $overview->build($filter),
            'top_accounts' => $topAccounts->build($filter),
            'sync' => $this->sync($filter, $user->isSupervisorOrAbove()),
        ]);
    }

    /** @return array{last_synced_at:?string, errors:list<array{account:string,error:string}>} */
    private function sync(AdsFilter $filter, bool $withErrors): array
    {
        $last = AdAccount::query()->where('is_active', true)
            ->when($filter->accountIds !== null, fn ($q) => $q->whereIn('id', $filter->accountIds))
            ->max('last_synced_at');

        // Connection errors carry platform text and are for the people who manage the connections.
        $errors = ! $withErrors ? [] : AdPlatformConnection::query()
            ->where('status', 'error')->orderBy('id')->get(['id', 'name', 'last_error'])
            ->map(fn (AdPlatformConnection $c) => ['account' => $c->name, 'error' => (string) $c->last_error])->all();

        return [
            'last_synced_at' => $last === null ? null : Carbon::parse($last, 'UTC')->toIso8601String(),
            'errors' => $errors,
        ];
    }
}
