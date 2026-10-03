<?php

namespace App\Http\Controllers\Concerns;

use App\Ads\Platforms\AdPlatform;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsOverview;
use App\Models\MediaBuyer;
use App\Models\User;

/** Props every Ads report page shares. */
trait BuildsAdsPages
{
    /** @return array{from:string,to:string,platform:?string,buyer:?int} the filter as it was actually applied */
    protected function filterProps(AdsFilter $f): array
    {
        return [
            'from' => $f->fromDate(),
            'to' => $f->toDate(),
            'platform' => $f->platform,
            'buyer' => $f->buyerId,
        ];
    }

    /**
     * Shared by every report page: buyer filter options, platform values and the display currency.
     *
     * @return array{buyers: list<array{id:int,name:string}>, platforms: list<string>, currency: string}
     */
    protected function commonProps(User $user, AdsFilter $f): array
    {
        return [
            'buyers' => $this->buyerOptions($user),
            'platforms' => $this->platformValues(),
            'currency' => app(AdsOverview::class)->currency($f),
        ];
    }

    /** @return list<array{id:int,name:string}> the buyers a supervisor+ may filter by (buyers get no buyer filter) */
    protected function buyerOptions(User $user): array
    {
        if (! $user->isSupervisorOrAbove()) {
            return [];
        }

        return MediaBuyer::query()
            ->orderBy('name')->get(['id', 'name'])
            ->map(fn (MediaBuyer $b) => ['id' => $b->id, 'name' => $b->name])->all();
    }

    /** @return list<string> */
    protected function platformValues(): array
    {
        return array_map(fn (AdPlatform $p) => $p->value, AdPlatform::cases());
    }
}
