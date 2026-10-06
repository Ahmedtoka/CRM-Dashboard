<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Control\AdWriteService;
use App\Ads\Control\StopAdvisor;
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

/** «محتاج قرار» (U 5.2, spec 4.1): launch approvals on top, stop suggestions, S5 alerts slot, and the old Actions log. */
class DecisionsController extends Controller
{
    use BuildsAdsPages;

    public const TABS = ['open', 'snoozed', 'closed', 'log'];

    public function __invoke(Request $request, StopAdvisor $advisor, AdWriteService $writer, WriteActionLog $log,
        PendingApprovals $approvals, DecisionCounter $counter): Response
    {
        $user = $request->user();
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'open';
        $filter = AdsFilter::fromRequest($request, $user, 'last7');
        $logFilters = [
            'user' => is_numeric($request->query('who')) ? (int) $request->query('who') : null,
            'level' => in_array($request->query('level'), AdWriteService::LEVELS, true) ? (string) $request->query('level') : null,
            'result' => in_array($request->query('result'), ['ok', 'error', 'pending'], true) ? (string) $request->query('result') : null,
        ];

        $pending = $approvals->count($user);
        $suggestions = [];
        if ($tab === 'open') {
            $found = $advisor->suggest(DecisionCounter::window($filter));
            $accounts = AdAccount::query()->whereIn('id', array_unique(array_column($found, 'account_id')))->get(['id', 'is_active', 'write_enabled', 'platform', 'external_id']);
            $can = $writer->canWriteMany($user, $accounts);
            $suggestions = array_map(fn (array $s) => $s + ['can_write' => $can[$s['account_id']] ?? false], $found);
        }
        // The visit refreshes the viewer's badge; the open tab already has the numbers. The badge counts the viewer's
        // whole scope, so a narrowed filter (accounts, buyer, platform) recounts instead of storing a partial number.
        $narrowed = $request->filled('accounts') || $request->filled('buyer') || $request->filled('platform');
        $open = match (true) {
            ! DecisionCounter::eligible($user) => count($suggestions) + $pending,
            $tab === 'open' && ! $narrowed => DecisionCounter::store($user, count($suggestions) + $pending),
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
            'counts' => ['open' => $tab === 'open' ? count($suggestions) + $pending : $open, 'snoozed' => 0, 'closed' => 0],
            'approvals' => $approvals->canApprove($user) ? ['count' => $pending, 'href' => '/ads/approvals', 'items' => $approvals->items($user)] : null,
            'suggestions' => $suggestions,
            'alerts' => [],
            'log' => $logRows,
            'log_users' => collect($userRows)->filter(fn (array $r) => $r['user_id'] !== null)
                ->map(fn (array $r) => ['id' => (int) $r['user_id'], 'name' => (string) $r['user']])->unique('id')->values()->all(),
        ]);
    }
}
