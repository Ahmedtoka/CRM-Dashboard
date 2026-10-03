<?php

namespace App\Ads\Buyers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final class BuyerResolver
{
    /**
     * ad_daily_metrics (alias m) within [from,to], each row tagged with the buyer who held the account that day
     * (buyer_id null = unassigned). Callers add their own aggregates with selectRaw.
     */
    public function metricsWithBuyer(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table('ad_daily_metrics as m')
            ->leftJoin('ad_account_assignments as a', function (JoinClause $j) {
                $j->on('a.ad_account_id', '=', 'm.ad_account_id')
                    ->whereColumn('m.date', '>=', 'a.starts_on')
                    ->where(fn ($q) => $q->whereNull('a.ends_on')->orWhereColumn('m.date', '<=', 'a.ends_on'));
            })
            ->whereBetween('m.date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('a.media_buyer_id as buyer_id');
    }
}
