<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsOverview;
use App\Ads\Reports\AdsQuery;
use App\Ads\Reports\BuyerScorecard;
use App\Ads\Reports\RevenueSummary;
use App\Ads\Reports\TopAccounts;
use App\Analytics\AdsReport;
use App\Http\Controllers\Concerns\BuildsAdsPages;
use App\Http\Controllers\Controller;
use App\Inbox\Outcomes\ChatFunnel;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** «الأرقام» (D11, U 2.1): the old Overview report, the buyers compare and the old /reports/ads chat table. */
class NumbersController extends Controller
{
    use BuildsAdsPages;

    public const SECTIONS = ['buyers', 'chat', 'accounts'];

    public function __invoke(Request $request, AdsOverview $overview, RevenueSummary $summary, TopAccounts $top,
        BuyerScorecard $cards, AdsReport $chat, AdsQuery $q, ChatFunnel $funnel): Response
    {
        $user = $request->user();
        $filter = AdsFilter::fromRequest($request, $user, 'this_month');
        $manager = $user->isSupervisorOrAbove();
        $sync = $this->syncProps($filter, $manager);

        return Inertia::render('Ads/Numbers', [
            'filters' => $this->filterProps($filter, $request) + [
                'section' => in_array($request->query('section'), self::SECTIONS, true) ? (string) $request->query('section') : null,
            ],
            ...$this->commonProps($user, $filter),
            'account_options' => $this->accountOptions($request, $filter),
            'freshness' => $sync['oldest']['last_synced_at'] ?? null,
            'sync_errors' => $sync['errors'],
            // The old Overview's sync block (stalest account by name, last sync), kept for the freshness detail.
            'sync' => $sync,
            'overview' => $overview->build($filter),
            'summary' => $summary->build($filter, $manager),
            'top_accounts' => $top->build($filter),
            'buyers' => $cards->build($filter),
            'chat_campaigns' => $manager ? $this->chat($filter, $chat, $q) : null,
            // S3 (spec 5.3): the chat funnel and why-not-bought over the page's own filter (a buyer: her accounts only), deferred.
            'chatFunnel' => Inertia::defer(fn () => $funnel->forFilter($filter), 'funnel'),
        ]);
    }

    /** Conversations → orders by campaign (old /reports/ads), spend from the synced metrics instead of a live Graph call. */
    private function chat(AdsFilter $f, AdsReport $report, AdsQuery $q): array
    {
        $spend = $q->sums($f->allSpend(), ['campaign' => 'camp.name'], fn ($b) => $b->leftJoin('ad_campaigns as camp', 'camp.id', '=', 'ad.ad_campaign_id'))
            ->filter(fn (object $r) => $r->campaign !== null && $r->campaign !== '')
            ->mapWithKeys(fn (object $r) => [(string) $r->campaign => [
                'campaign_id' => '', 'campaign_name' => (string) $r->campaign, 'spend' => round((float) $r->spend, 2), 'currency' => null,
            ]])->all();

        return $report->build($f->startUtc(), $f->endUtc(), null, $spend);
    }
}
