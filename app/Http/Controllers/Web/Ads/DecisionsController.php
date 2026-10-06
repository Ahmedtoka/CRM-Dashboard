<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Alerts\AlertFeed;
use App\Ads\Control\AdWriteService;
use App\Ads\Control\WriteActionLog;
use App\Ads\Decisions\DecisionCounter;
use App\Ads\Decisions\PendingApprovals;
use App\Ads\Reports\AdsFilter;
use App\Http\Controllers\Concerns\BuildsAdsPages;
use App\Http\Controllers\Controller;
use App\Models\AdAccount;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «محتاج قرار» (U 5.2, spec 4.1): launch approvals on top, stop suggestions, the S5 alert cards, and the old Actions log.
 * The feed follows the page tabs (snoozed = the feed's «بعدين»); a stop suggestion for an ad that already has a live
 * alert is folded into that ad's card.
 */
class DecisionsController extends Controller
{
    use BuildsAdsPages;

    public const TABS = ['open', 'snoozed', 'closed', 'log'];

    /** Page tab → AlertFeed tab. */
    public const FEED_TABS = ['open' => 'open', 'snoozed' => 'later', 'closed' => 'closed', 'log' => 'log'];

    public function __invoke(Request $request, AdWriteService $writer, WriteActionLog $log,
        PendingApprovals $approvals, DecisionCounter $counter, AlertFeed $feed): Response
    {
        $user = $request->user();
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'open';
        $filter = AdsFilter::fromRequest($request, $user, 'last7');
        $logFilters = [
            'user' => is_numeric($request->query('who')) ? (int) $request->query('who') : null,
            'level' => in_array($request->query('level'), AdWriteService::LEVELS, true) ? (string) $request->query('level') : null,
            'result' => in_array($request->query('result'), ['ok', 'error', 'pending'], true) ? (string) $request->query('result') : null,
        ];

        $feedData = $feed->forUser($user, self::FEED_TABS[$tab]);
        $suggestions = [];
        $openNow = null; // the open tab counts here (DecisionCounter::breakdown), on the page's filter
        if ($tab === 'open') {
            $b = $counter->breakdown($user, $filter);
            $openNow = $b['total'];
            $accounts = AdAccount::query()->whereIn('id', array_unique(array_column($b['suggestions'], 'account_id')))->get(['id', 'is_active', 'write_enabled', 'platform', 'external_id']);
            $can = $writer->canWriteMany($user, $accounts);
            $suggestions = array_map(fn (array $s) => $s + ['can_write' => $can[$s['account_id']] ?? false], $b['suggestions']);
        }
        $pending = $approvals->count($user);
        // The visit refreshes the viewer's badge; the open tab already has the numbers. The badge counts the viewer's
        // whole scope, so a narrowed filter (accounts, buyer, platform) recounts instead of storing a partial number.
        $narrowed = $request->filled('accounts') || $request->filled('buyer') || $request->filled('platform');
        $open = match (true) {
            $openNow !== null && ! DecisionCounter::eligible($user) => $openNow,
            $openNow !== null && ! $narrowed => DecisionCounter::store($user, $openNow),
            ! DecisionCounter::eligible($user) => $counter->breakdown($user)['total'],
            default => DecisionCounter::cached($user) ?? $counter->refresh($user),
        };
        $logRows = $tab === 'log' ? $log->rows($user, $logFilters) : [];
        // The «مين» options come from the whole log, so picking one person never empties the list of the others.
        $filtered = array_filter($logFilters, fn ($v) => $v !== null) !== [];
        $userRows = $tab === 'log' && $filtered ? $log->rows($user) : $logRows;

        return Inertia::render('Ads/Decisions', [
            'filters' => $this->filterProps($filter, $request) + ['tab' => $tab, 'who' => $logFilters['user'], 'level' => $logFilters['level'], 'result' => $logFilters['result']],
            ...$this->commonProps($user, $filter),
            'account_options' => $this->accountOptions($request, $filter),
            'freshness' => $this->syncProps($filter, false)['oldest']['last_synced_at'] ?? null,
            'counts' => [
                'open' => $openNow ?? $open,
                'snoozed' => $feedData['meta']['counts']['later'], 'closed' => $feedData['meta']['counts']['closed'],
            ],
            'approvals' => $approvals->canApprove($user) ? ['count' => $pending, 'href' => '/ads/approvals', 'items' => $approvals->items($user)] : null,
            'suggestions' => $suggestions,
            'alerts' => $feedData['items'],
            'alertsMeta' => $feedData['meta'],
            'log' => $logRows,
            'log_users' => collect($userRows)->filter(fn (array $r) => $r['user_id'] !== null)
                ->map(fn (array $r) => ['id' => (int) $r['user_id'], 'name' => (string) $r['user']])->unique('id')->values()->all(),
        ]);
    }
}
