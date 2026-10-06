<?php

namespace App\Ads\Decisions;

use App\Ads\Alerts\AlertFeed;
use App\Ads\Control\StopAdvisor;
use App\Ads\Reports\AdsFilter;
use App\Enums\UserRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The «محتاج قرار» badge: approvals waiting for the viewer + open stop suggestions in scope (minus the ads folded into an
 * alert card) + open S5 alert cards (before the buyer cap).
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

    /** Computes the count now and caches it. */
    public function refresh(User $u): int
    {
        $f = self::window(AdsFilter::fromRequest(Request::create('/ads/decisions'), $u, 'last7'));
        $feed = app(AlertFeed::class);
        $folded = $feed->adIdsWithLiveAlerts($u);
        $suggestions = array_filter($this->advisor->suggest($f), fn (array $s) => ! in_array((int) $s['ad_id'], $folded, true));

        return self::store($u, $this->approvals->count($u) + count($suggestions) + $feed->openCardCount($u));
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

    private static function key(User $u): string
    {
        return 'ads:decisions:count:'.$u->id;
    }
}
