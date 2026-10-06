<?php

namespace App\Ads\Alerts;

use App\Ads\Control\AdWriteService;
use App\Enums\UserRole;
use App\Models\AdAccount;
use App\Models\AdsAlert;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * «محتاج قرار» alerts section (spec 7.3, U 5.2): one card per ad with its reasons stacked (severity = highest), product
 * cards for out-of-stock ads, sorted by severity then money at risk per day; buyers capped at 7 open cards.
 */
final class AlertFeed
{
    public const BUYER_CAP = 7;

    public const TABS = ['open', 'later', 'closed', 'log'];

    public const CLOSED_DAYS = 7;

    public const CLOSED_LIMIT = 100;

    /** Finding action → the card's one primary verb (U 5.2). */
    public const VERBS = [
        'stop' => 'stop', 'run' => 'run', 'check_stock' => 'open_stock', 'look' => 'open_campaigns', 'edit_ad' => 'open_library',
        'open_queue' => 'open_queue', 'add_replacement' => 'add_replacement', 'view_orders' => 'view_orders', 'none' => 'why',
    ];

    public function __construct(
        private readonly AlertScope $scope,
        private readonly AdWriteService $writer,
        private readonly RuleSettings $settings,
    ) {}

    /** @return array{items: list<array<string, mixed>>, meta: array<string, mixed>} */
    public function forUser(User $u, string $tab = 'open'): array
    {
        $tab = in_array($tab, self::TABS, true) ? $tab : 'open';
        $items = $tab === 'log' ? [] : $this->cards($u, $this->rows($u, $tab));
        $hidden = 0;
        if ($tab === 'open' && $u->role === UserRole::MediaBuyer && count($items) > self::BUYER_CAP) {
            $hidden = count($items) - self::BUYER_CAP;
            $items = array_slice($items, 0, self::BUYER_CAP);
        }

        return ['items' => $items, 'meta' => [
            'tab' => $tab, 'counts' => $this->counts($u), 'hidden_by_cap' => $hidden, 'buyer_cap' => self::BUYER_CAP,
            'shadow' => ! $this->settings->notifyEnabled(), 'can_toggle' => $this->scope->canManage($u),
        ]];
    }

    /** @return list<int> */
    public function adIdsWithLiveAlerts(User $u): array
    {
        return $this->scope->visible($u)->whereIn('state', AdsAlert::LIVE_STATES)->whereNotNull('ad_id')->pluck('ad_id')
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** @return array{open: int, later: int, closed: int} */
    private function counts(User $u): array
    {
        return [
            'open' => $this->scope->visible($u)->where('state', AdsAlert::OPEN)->count(),
            'later' => $this->scope->visible($u)->where('state', AdsAlert::SNOOZED)->count(),
            'closed' => $this->scope->visible($u)->whereIn('state', AdsAlert::CLOSED_STATES)->where('closed_at', '>=', now()->subDays(self::CLOSED_DAYS))->count(),
        ];
    }

    /** @return Collection<int, AdsAlert> */
    private function rows(User $u, string $tab): Collection
    {
        $q = $this->scope->visible($u)->with([
            'ad:id,ad_account_id,external_id,name,thumbnail_url', 'ad.account:id,name', 'product:id,title',
            'account:id,name', 'buyer:id,name', 'closedBy:id,name',
        ]);

        return match ($tab) {
            'later' => $q->where('state', AdsAlert::SNOOZED)->get(),
            'closed' => $q->whereIn('state', AdsAlert::CLOSED_STATES)->where('closed_at', '>=', now()->subDays(self::CLOSED_DAYS))
                ->orderByDesc('closed_at')->limit(self::CLOSED_LIMIT)->get(),
            default => $q->where('state', AdsAlert::OPEN)->get(),
        };
    }

    /**
     * @param  Collection<int, AdsAlert>  $rows
     * @return list<array<string, mixed>>
     */
    private function cards(User $u, Collection $rows): array
    {
        $accounts = AdAccount::query()->whereIn('id', $rows->pluck('ad_account_id')->filter()->unique()->values())->get();
        $canWrite = $this->writer->canWriteMany($u, $accounts);
        $perAd = $rows->whereNotNull('ad_id')->countBy('ad_id');

        $groups = [];
        foreach ($rows as $a) {
            $key = match (true) {
                $a->rule_id === 'all.out_of_stock' && $a->product_id !== null && ($perAd[$a->ad_id] ?? 0) === 1 => 'product:'.$a->product_id,
                $a->ad_id !== null => 'ad:'.$a->ad_id,
                default => 'account:'.($a->ad_account_id ?? 0).':'.$a->rule_id,
            };
            $groups[$key][] = $a;
        }

        $cards = [];
        foreach ($groups as $key => $alerts) {
            $cards[] = $this->card($u, (string) $key, collect($alerts), $canWrite);
        }
        usort($cards, fn (array $x, array $y) => [Severity::rank($y['severity']), $y['money_at_risk_per_day'], $x['first_fired_at'] ?? '']
            <=> [Severity::rank($x['severity']), $x['money_at_risk_per_day'], $y['first_fired_at'] ?? '']);

        return $cards;
    }

    /**
     * @param  Collection<int, AdsAlert>  $alerts
     * @param  array<int, bool>  $canWrite
     * @return array<string, mixed>
     */
    private function card(User $u, string $key, Collection $alerts, array $canWrite): array
    {
        $alerts = $alerts->sortByDesc(fn (AdsAlert $a) => Severity::rank($a->severity))->values();
        $top = $alerts->first();
        $kind = str_starts_with($key, 'product:') ? 'product' : (str_starts_with($key, 'ad:') ? 'ad' : 'account');
        $moneys = $alerts->map(fn (AdsAlert $a) => (float) $a->money_at_risk_per_day);

        return [
            'key' => $key,
            'kind' => $kind,
            'severity' => $top->severity,
            'money_at_risk_per_day' => round($kind === 'product' ? $moneys->sum() : (float) $moneys->max(), 2),
            'first_fired_at' => $alerts->map(fn (AdsAlert $a) => $a->first_fired_at?->toIso8601String())->filter()->min(),
            'ad' => $kind === 'ad' ? $this->adRef($top, $canWrite) : null,
            'product' => $top->product !== null ? ['id' => (int) $top->product->id, 'title' => (string) $top->product->title] : null,
            'account' => $top->account !== null ? ['id' => (int) $top->account->id, 'name' => (string) $top->account->name] : null,
            'ads' => $kind === 'product' ? $alerts->map(fn (AdsAlert $a) => $this->adRef($a, $canWrite) + ['alert_id' => $a->id])->values()->all() : [],
            'reasons' => ($kind === 'product' ? $alerts->take(1) : $alerts)->map(fn (AdsAlert $a) => $this->reason($u, $a))->values()->all(),
            'primary' => ['verb' => self::VERBS[$top->action] ?? 'why', 'action' => $top->action, 'alert_id' => $top->id, 'href' => $this->href($top)],
            'alert_ids' => $alerts->pluck('id')->all(),
        ];
    }

    /**
     * @param  array<int, bool>  $canWrite
     * @return array<string, mixed>
     */
    private function adRef(AdsAlert $a, array $canWrite): array
    {
        $ad = $a->ad;

        return [
            'id' => (int) $ad->id, 'external_id' => (string) $ad->external_id, 'name' => (string) $ad->name, 'thumbnail_url' => $ad->thumbnail_url,
            'account_id' => (int) $ad->ad_account_id, 'account' => (string) ($ad->account?->name ?? ''), 'buyer' => $a->buyer?->name,
            'can_write' => (bool) ($canWrite[$ad->ad_account_id] ?? false),
        ];
    }

    /** @return array<string, mixed> */
    private function reason(User $u, AdsAlert $a): array
    {
        return [
            'alert_id' => $a->id, 'rule_id' => $a->rule_id, 'severity' => $a->severity, 'action' => $a->action,
            'sentence_key' => $a->sentence_key, 'params' => $a->params ?? [], 'evidence' => $a->evidence ?? [],
            'first_fired_at' => $a->first_fired_at?->toIso8601String(), 'seen' => $a->seen_at !== null, 'state' => $a->state,
            'snoozed_until' => $a->snoozed_until?->toIso8601String(), 'closed_at' => $a->closed_at?->toIso8601String(),
            'closed_by' => $a->closedBy?->name, 'resolved_reason' => $a->resolved_reason, 'dismiss_reason' => $a->dismiss_reason,
            'can_dismiss' => $this->scope->canDismiss($u, $a),
        ];
    }

    private function href(AdsAlert $a): ?string
    {
        return match ($a->action) {
            'check_stock' => '/ads/stock',
            'look' => '/ads/explorer?accounts='.$a->ad_account_id,
            'edit_ad', 'add_replacement' => '/ads/materials',
            'open_queue' => '/board',
            default => null,
        };
    }
}
