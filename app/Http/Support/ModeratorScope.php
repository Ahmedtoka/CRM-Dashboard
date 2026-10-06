<?php

namespace App\Http\Support;

use App\Enums\Platform;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Query constraints limiting moderators to their platforms. Supervisors and admins are
 * never constrained. Used for top-level lists and for nested data (a customer's
 * identities and orders) so platform scoping cannot leak through a parent resource.
 */
final class ModeratorScope
{
    /**
     * @return array<int, string>
     */
    public static function platformValues(User $u): array
    {
        return array_map(fn (Platform $p) => $p->value, $u->platforms());
    }

    /**
     * Orders on the moderator's platforms, plus orders the moderator created.
     */
    public static function orders(Builder|Relation $q, User $u): Builder|Relation
    {
        if ($u->isSupervisorOrAbove()) {
            return $q;
        }

        return $q->where(fn ($w) => $w
            ->whereIn('orders.platform', self::platformValues($u))
            ->orWhere('orders.created_by_id', $u->id));
    }

    /**
     * Support cases on the moderator's platforms (spec §4). Cases carry their own
     * `platform` column (copied from the conversation at record time), so unlike
     * orders there is no "created it themselves" exception to fall back on.
     */
    public static function cases(Builder|Relation $q, User $u): Builder|Relation
    {
        if ($u->isSupervisorOrAbove()) {
            return $q;
        }

        return $q->whereIn('support_cases.platform', self::platformValues($u));
    }

    public static function identities(Builder|Relation $q, User $u): Builder|Relation
    {
        if ($u->isSupervisorOrAbove()) {
            return $q;
        }

        return $q->whereIn('customer_identities.platform', self::platformValues($u));
    }

    /**
     * Customers with at least one identity on an allowed platform.
     */
    public static function customers(Builder|Relation $q, User $u): Builder|Relation
    {
        if ($u->isSupervisorOrAbove()) {
            return $q;
        }

        return $q->whereHas('identities', fn ($i) => self::identities($i, $u));
    }

    /**
     * Eager-load map for a customer as seen by $u: scoped identities and orders.
     *
     * @return array<string, \Closure>
     */
    /** The order's ad and its campaign, as `ad_source` needs them. */
    public const ORDER_AD_RELATIONS = ['ad:id,name,thumbnail_url,ad_campaign_id,ad_account_id,external_id', 'ad.campaign:id,name', 'ad.account:id,platform,external_id'];

    public static function customerRelations(User $u, int $orderLimit = 0, bool $withAds = false): array
    {
        // Control room S3: web callers also load each order's ad (OrderResource `ad_source`, web only).
        $with = $withAds
            ? ['items', 'createdBy', ...self::ORDER_AD_RELATIONS]
            : ['items', 'createdBy'];

        return [
            'identities' => fn ($q) => self::identities($q, $u),
            'addresses',
            'orders' => fn ($q) => self::orders($q, $u)
                ->with($with)
                ->orderByDesc('id')
                ->when($orderLimit > 0, fn ($l) => $l->limit($orderLimit)),
        ];
    }
}
