<?php

namespace App\Http\Controllers\Concerns;

use App\Ads\Access\AdsScope;
use App\Ads\Platforms\AdPlatform;
use App\Ads\Reports\AdsFilter;
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

    /** @return list<array{id:int,name:string}> the buyers a user may filter by (a buyer sees only themselves) */
    protected function buyerOptions(User $user): array
    {
        $own = app(AdsScope::class)->buyerFor($user);

        return MediaBuyer::query()
            ->when(! $user->isSupervisorOrAbove(), fn ($q) => $q->whereKey($own?->id ?? 0))
            ->orderBy('name')->get(['id', 'name'])
            ->map(fn (MediaBuyer $b) => ['id' => $b->id, 'name' => $b->name])->all();
    }

    /** @return list<string> */
    protected function platformValues(): array
    {
        return array_map(fn (AdPlatform $p) => $p->value, AdPlatform::cases());
    }
}
