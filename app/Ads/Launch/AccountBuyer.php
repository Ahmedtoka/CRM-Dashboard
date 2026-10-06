<?php

namespace App\Ads\Launch;

use App\Ads\Reports\AdsFilter;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\MediaBuyer;
use Carbon\CarbonImmutable;

/** The buyer who holds an account today (Cairo day, dated assignments): the reviewer of every launch on it (D2). */
final class AccountBuyer
{
    public static function today(AdAccount $a): ?MediaBuyer
    {
        $day = CarbonImmutable::now(AdsFilter::TIMEZONE)->toDateString();
        $row = AdAccountAssignment::query()->with('buyer.user')
            ->where('ad_account_id', $a->id)->where('starts_on', '<=', $day)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $day))
            ->orderByDesc('starts_on')->orderByDesc('id')->first();
        $buyer = $row?->buyer;

        return $buyer !== null && $buyer->is_active && $buyer->user !== null && $buyer->user->is_active ? $buyer : null;
    }
}
