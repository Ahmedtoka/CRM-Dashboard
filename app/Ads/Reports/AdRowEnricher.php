<?php

namespace App\Ads\Reports;

use App\Ads\Control\AdWriteService;
use App\Models\AdAccount;
use App\Models\User;

/** Turns RunningCreatives rows into AdRow/AdCard rows: health badges, 14-day series, Stop/Run allowed. Grouped reads only. */
final class AdRowEnricher
{
    public function __construct(
        private readonly AdHealth $health,
        private readonly AdDailySeries $series,
        private readonly AdWriteService $writes,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function enrich(array $rows, AdsFilter $f, User $u): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(fn (array $r) => (int) $r['id'], $rows);
        $signals = $this->health->signals($ids, $f);
        [$from, $to] = AdDailySeries::window($f->to);
        $series = $this->series->forAds($ids, $from, $to);
        $accountIds = array_values(array_unique(array_map(fn (array $r) => (int) $r['account_id'], $rows)));
        $can = $this->writes->canWriteMany($u, AdAccount::query()->whereIn('id', $accountIds)->get(['id', 'is_active', 'write_enabled', 'platform', 'external_id']));

        return array_map(function (array $r) use ($signals, $series, $can) {
            $id = (int) $r['id'];
            $r['need_stop'] = isset($signals['need_stop'][$id]);
            $r['tier'] = $signals['tiers'][$id] ?? null;
            $r['health'] = AdHealth::badges($r + ['scored' => array_key_exists($id, $signals['tiers'])]);
            $r['series'] = $series[$id] ?? [];
            $r['can_write'] = $can[(int) $r['account_id']] ?? false;

            return $r;
        }, $rows);
    }
}
