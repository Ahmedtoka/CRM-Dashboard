<?php

namespace App\Ads\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * At most two health badges per ad from signals that exist today (U 3.3): material need-stop, WinnerScorer tier,
 * AdInsights fatigue, the scoring gate, parent paused. When S5 lands, the badge slot reads the open alert instead.
 */
final class AdHealth
{
    public const KEYS = ['out_of_stock', 'losing', 'tired', 'too_early', 'parent_paused', 'winning'];

    public const MAX = 2;

    public const NEED_STOP_MATERIAL_STATUSES = ['activated', 'live'];

    public function __construct(private readonly WinnerScorer $scorer) {}

    /** @return list<string> */
    public static function badges(array $row): array
    {
        $on = [
            'out_of_stock' => (bool) ($row['need_stop'] ?? false),
            'losing' => ($row['tier'] ?? null) === 'loser',
            'tired' => (bool) ($row['fatigue']['flag'] ?? false),
            'too_early' => ($row['scored'] ?? true) === false && strtoupper((string) ($row['effective_status'] ?? '')) === 'ACTIVE',
            'parent_paused' => (bool) ($row['parent_paused'] ?? false),
            'winning' => ($row['tier'] ?? null) === 'winner',
        ];

        return array_slice(array_keys(array_filter($on)), 0, self::MAX);
    }

    /** @return array{tiers: array<int, string>, need_stop: array<int, true>} */
    public function signals(array $adIds, AdsFilter $f): array
    {
        if ($adIds === []) {
            return ['tiers' => [], 'need_stop' => []];
        }
        $tiers = array_intersect_key($this->scorer->tiers($f), array_flip($adIds));
        $need = $this->needStopQuery()->whereIn('l.ad_id', $adIds)->pluck('l.ad_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])->all();

        return ['tiers' => $tiers, 'need_stop' => $need];
    }

    /** @return list<int> */
    public function needStopIds(AdsFilter $f): array
    {
        if ($f->isEmpty()) {
            return [];
        }

        return $this->needStopQuery()->join('ads as ad', 'ad.id', '=', 'l.ad_id')
            ->when($f->accountIds !== null, fn ($q) => $q->whereIn('ad.ad_account_id', $f->accountIds))
            ->distinct()->pluck('l.ad_id')->map(fn ($id) => (int) $id)->values()->all();
    }

    private function needStopQuery(): Builder
    {
        return DB::table('ad_material_ads as l')->join('ad_materials as mat', 'mat.id', '=', 'l.ad_material_id')
            ->whereIn('mat.status', self::NEED_STOP_MATERIAL_STATUSES)->whereNotNull('mat.need_stop_at');
    }
}
