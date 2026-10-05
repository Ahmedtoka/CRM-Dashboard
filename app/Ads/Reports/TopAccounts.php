<?php

namespace App\Ads\Reports;

use App\Models\MediaBuyer;
use Carbon\CarbonImmutable;

final class TopAccounts
{
    public function __construct(private readonly AdsQuery $q) {}

    /**
     * Accounts with spend in range. `buyer` = the holder on the range's last day; when the rows are
     * limited to one buyer (a buyer user, or an admin's buyer filter) it is that buyer, since only
     * their rows are counted.
     *
     * @return list<array{id:int, name:string, external_id:string, platform:string, buyer:?string, spend:float, spend_tax:float, purchase_value:float, purchases:float, roas:?float, status:?string, last_synced_at:?string}>
     */
    public function build(AdsFilter $f): array
    {
        $f = $f->allSpend();
        $rows = $this->q->sums(
            $f,
            ['id' => 'acc.id', 'name' => 'acc.name', 'external_id' => 'acc.external_id', 'platform' => 'acc.platform', 'status' => 'acc.status', 'last_synced_at' => 'acc.last_synced_at'],
            fn ($b) => $b->orderByDesc('spend')->orderBy('acc.id'),
        );
        if ($rows->isEmpty()) {
            return [];
        }

        $owners = $this->q->owners($rows->pluck('id')->all());
        $holders = $rows->mapWithKeys(fn ($r) => [(int) $r->id => $f->restrictBuyerId ?? $f->buyerId ?? $this->q->ownerOn($owners, (int) $r->id, $f->toDate())]);
        $names = MediaBuyer::query()->whereIn('id', $holders->filter()->unique()->values())->pluck('name', 'id');

        $blends = $this->q->accountTotalsByAccount($f);

        return $rows->map(function (object $r) use ($holders, $names, $blends) {
            $d = $this->q->applyBlend($this->q->derive($r), $blends[(int) $r->id] ?? null);
            $holder = $holders[(int) $r->id];

            return [
                'id' => (int) $r->id,
                'name' => (string) $r->name,
                'external_id' => (string) $r->external_id,
                'platform' => (string) $r->platform,
                'buyer' => $holder !== null ? ($names[$holder] ?? null) : null,
                'spend' => $d['spend'],
                'spend_tax' => $d['spend_tax'],
                'purchase_value' => $d['purchase_value'],
                'purchases' => $d['purchases'],
                'roas' => $d['roas'],
                'status' => $r->status,
                'last_synced_at' => $r->last_synced_at !== null ? CarbonImmutable::parse((string) $r->last_synced_at, 'UTC')->toIso8601String() : null,
            ];
        })->values()->all();
    }
}
