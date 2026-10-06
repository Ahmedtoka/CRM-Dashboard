<?php

namespace App\Ads\Decisions;

use App\Ads\Alerts\AlertFeed;
use App\Ads\Control\StopAdvisor;
use App\Ads\Launch\LaunchMoved;
use App\Ads\Launch\LaunchPolicy;
use App\Ads\Launch\LaunchState;
use App\Ads\Reports\AdsFilter;
use App\Enums\UserRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The ONE «open decisions» number (final fix 8). Every surface shows this count, never its own sum: the nav badge
 * («محتاج قرار»), the /ads Today decisions block, the 09:00 digest («N قرار مفتوح») and the Decisions page "open" tab.
 *
 * Definition, per viewer (each part already scoped to what the viewer may see):
 *   open decisions = launch approvals awaiting this viewer (PendingApprovals::count)
 *                  + open S5 alert cards in the viewer's AlertScope, before the buyer cap (AlertFeed::openCardCount)
 *                  + stop suggestions of the last SUGGEST_DAYS days (StopAdvisor) whose ad has no live alert card
 *                    (those are folded into the ad's card, so they are not counted twice).
 * breakdown() returns the parts and the total; a page with a narrowed filter (accounts, buyer, platform) passes its filter
 * and gets the same definition on that slice of suggestions.
 *
 * Never computed inside an ordinary page request: the shared Inertia prop reads the cached number only (a miss = no
 * badge). The number is written by refresh(): the Decisions page visit (which has the suggestions anyway) and the
 * hourly `ads:decisions-count` run after the sync and the attribution.
 */
class DecisionCounter
{
    /** Longer than the hourly refresh so the badge never blinks out between two runs. */
    public const CACHE_SECONDS = 7200;

    public const SUGGEST_DAYS = 14;

    public function __construct(private readonly StopAdvisor $advisor, private readonly PendingApprovals $approvals) {}

    /** The suggestions window: the 14 days ending today (the old Actions page window), whatever range the page shows. */
    public static function window(AdsFilter $f): AdsFilter
    {
        $today = CarbonImmutable::now(AdsFilter::TIMEZONE)->startOfDay();

        return $f->with(['from' => $today->subDays(self::SUGGEST_DAYS - 1), 'to' => $today]);
    }

    /** Who has the badge: media buyers and supervisors and above. */
    public static function eligible(?User $u): bool
    {
        return $u !== null && ($u->isSupervisorOrAbove() || $u->role === UserRole::MediaBuyer);
    }

    /** The cached count, or null when none is cached (never computes). */
    public static function cached(User $u): ?int
    {
        $v = Cache::get(self::key($u));

        return is_numeric($v) ? (int) $v : null;
    }

    /**
     * The parts and the total of the open-decisions count (see the class docblock). $f defaults to the viewer's whole
     * scope; its range is replaced by the suggestions window. $raw = stop suggestions the caller already has for that
     * window (folded here, never counted raw).
     *
     * @param  list<array<string, mixed>>|null  $raw
     * @return array{approvals: int, suggestions: list<array<string, mixed>>, alert_cards: int, total: int}
     */
    public function breakdown(User $u, ?AdsFilter $f = null, ?array $raw = null): array
    {
        $f = self::window($f ?? AdsFilter::fromRequest(Request::create('/ads/decisions'), $u, 'last7'));
        $feed = app(AlertFeed::class);
        $folded = $feed->adIdsWithLiveAlerts($u);
        $suggestions = array_values(array_filter($raw ?? $this->advisor->suggest($f), fn (array $s) => ! in_array((int) $s['ad_id'], $folded, true)));
        $approvals = $this->approvals->count($u);
        $cards = $feed->openCardCount($u);

        return ['approvals' => $approvals, 'suggestions' => $suggestions, 'alert_cards' => $cards, 'total' => $approvals + count($suggestions) + $cards];
    }

    /** Computes the count now (the viewer's whole scope) and caches it for the badge. */
    public function refresh(User $u): int
    {
        return self::store($u, $this->breakdown($u)['total']);
    }

    /** Caches a count the caller already has (the Decisions page). */
    public static function store(User $u, int $count): int
    {
        Cache::put(self::key($u), $count, self::CACHE_SECONDS);

        return $count;
    }

    public static function forget(User $u): void
    {
        Cache::forget(self::key($u));
    }

    /**
     * LaunchMoved listener (final review B-m8): a launch entering or leaving awaiting_approval changes every approver's
     * count, so their cached number is dropped (the next Decisions / Today visit or the hourly run writes it again).
     */
    public static function onLaunchMoved(LaunchMoved $e): void
    {
        $awaiting = LaunchState::AwaitingApproval;
        if ($e->from === $e->to || ($e->to !== $awaiting && $e->from !== $awaiting)) {
            return;
        }
        User::query()->where('ads_authority', true)->where('is_active', true)->get()
            ->filter(fn (User $u) => LaunchPolicy::canApprove($u))
            ->each(fn (User $u) => self::forget($u));
    }

    private static function key(User $u): string
    {
        return 'ads:decisions:count:'.$u->id;
    }
}
