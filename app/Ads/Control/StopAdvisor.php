<?php

namespace App\Ads\Control;

use App\Ads\AdsSettings;
use App\Ads\Reports\AdHealth;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsQuery;
use App\Ads\Reports\Objective;
use App\Ads\Reports\WinnerScorer;
use App\Models\Ad;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Running ads worth stopping, each with the written reasons (spec 2.3). Candidates, all limited to ads whose OWN status is active (ACTIVE or ENABLE, never
 * effective_status) in the filter's scope:
 *   - loser tier or creative fatigue (WinnerScorer, same gate and numbers as the Winners page);
 *   - zero purchases with spend at or above `loser_min_spend` (never a Messages ad: those are judged on chats and real
 *     orders, U X7 / quick win 7);
 *   - ads linked to a live (activated before the S1 remap) material flagged `need_stop` (its product ran out of stock), spend or not.
 *
 * Reasons are {key, params} under `ads.reasons.*`; the first one that fired comes first.
 */
final class StopAdvisor
{
    /** Effective statuses of ads the platform no longer runs (A1c): never a Stop candidate. */
    public const STALE_STATUSES = ['ARCHIVED', 'DELETED', 'GONE'];

    public function __construct(
        private readonly WinnerScorer $scorer,
        private readonly AdsQuery $q,
        private readonly AdsSettings $settings,
    ) {}

    /** @return list<array{ad_id:int, external_id:string, account_id:int, account:string, platform:string, name:string, spend:float, spend_tax:float, roas:?float, reasons: list<array{key:string, params:array}>}> */
    public function suggest(AdsFilter $f): array
    {
        if ($f->isEmpty()) {
            return [];
        }

        $minSpend = (float) $this->settings->winnerThresholds()['loser_min_spend'];
        $days = (int) $f->from->diffInDays($f->to) + 1;
        $scored = collect($this->scorer->build($f, 'all'))->keyBy(fn (array $r) => $r['ad']['id']);

        $sums = $this->q->sums($f, ['ad_id' => 'm.ad_id'], fn ($b) => $this->notStale($b->whereIn('ad.status', AdWriteService::ACTIVE_STATUSES), 'ad.effective_status')
            ->leftJoin('ad_campaigns as oc', 'oc.id', '=', 'ad.ad_campaign_id')
            ->selectRaw('MAX(oc.objective) as objective, COALESCE(SUM(m.msg_conversations), 0) as msg_conversations'))
            ->keyBy(fn ($r) => (int) $r->ad_id);
        $needStop = $this->needStopMaterials($f);

        $reasons = [];
        $take = function (int $adId, array $reason) use (&$reasons): void {
            $reasons[$adId][] = $reason;
        };

        foreach ($scored as $adId => $row) {
            $byKey = collect($row['reasons'])->keyBy('key');
            if ($row['tier'] === 'loser' && $byKey->has('roas_below')) {
                $take($adId, $byKey['roas_below']);
            }
            if ($row['fatigue']['flag'] && $byKey->has('fatigue')) {
                $take($adId, $byKey['fatigue']);
            }
        }
        foreach ($sums as $adId => $s) {
            // Messages ads are judged on chats and real orders, never on pixel purchases (U X7, quick win 7).
            if (Objective::family($s->objective, (int) $s->msg_conversations) === Objective::MESSAGES) {
                continue;
            }
            if ((float) $s->purchases <= 0 && (float) $s->spend >= $minSpend && $minSpend > 0) {
                $take($adId, ['key' => 'no_purchases', 'params' => ['spend' => round((float) $s->spend, 2), 'days' => $days]]);
            }
        }
        foreach ($needStop as $adId => $titles) {
            $take($adId, ['key' => 'need_stop', 'params' => ['material' => implode(', ', $titles)]]);
        }

        if ($reasons === []) {
            return [];
        }

        $ads = $this->notStale(Ad::query()->with(['account:id,name,platform', 'campaign:id,name,objective'])->whereIn('id', array_keys($reasons))->whereIn('status', AdWriteService::ACTIVE_STATUSES), 'effective_status')->get()->keyBy('id');

        // Spend today (Cairo, every campaign) for the Stop dialog, one grouped query for all candidates.
        $day = CarbonImmutable::now(AdsFilter::TIMEZONE)->startOfDay();
        $today = $this->q->sums($f->allSpend()->with(['from' => $day, 'to' => $day]), ['ad_id' => 'm.ad_id'],
            fn ($b) => $b->whereIn('m.ad_id', array_keys($reasons)))
            ->mapWithKeys(fn ($r) => [(int) $r->ad_id => round((float) $r->spend, 2)]);

        $out = [];
        foreach ($reasons as $adId => $list) {
            $ad = $ads->get($adId);
            if ($ad === null) {
                continue;
            }
            $s = $sums->get($adId);
            $d = $s !== null ? $this->q->derive($s) : null;
            $out[] = [
                'ad_id' => $adId, 'external_id' => (string) $ad->external_id, 'account_id' => (int) $ad->ad_account_id,
                'account' => (string) ($ad->account?->name ?? ''), 'platform' => (string) ($ad->account?->platform ?? ''),
                'name' => (string) $ad->name,
                'spend' => (float) ($d['spend'] ?? 0), 'spend_tax' => (float) ($d['spend_tax'] ?? 0), 'roas' => $d['roas'] ?? null,
                'reasons' => $list,
                'objective' => Objective::family($ad->campaign?->objective, (int) ($s->msg_conversations ?? 0)),
                'thumbnail_url' => $ad->thumbnail_url,
                'campaign' => $ad->campaign?->name,
                'status' => $ad->status,
                'spend_today' => (float) ($today[$adId] ?? 0),
            ];
        }

        usort($out, fn (array $a, array $b) => [$b['spend'], $a['ad_id']] <=> [$a['spend'], $b['ad_id']]);

        return $out;
    }

    /**
     * An ad the platform reports as archived, deleted or gone is never a Stop candidate, whatever its stale own status
     * says (A1c, F-010).
     *
     * @template T of \Illuminate\Contracts\Database\Query\Builder
     *
     * @param  T  $q
     * @return T
     */
    private function notStale($q, string $column)
    {
        return $q->where(fn ($w) => $w->whereNull($column)->orWhereNotIn($column, self::STALE_STATUSES));
    }

    /**
     * Linked ads of live (activated) materials that are flagged need-stop, inside the filter's accounts.
     *
     * @return array<int, list<string>> ad id => material titles
     */
    private function needStopMaterials(AdsFilter $f): array
    {
        $q = DB::table('ad_material_ads as l')
            ->join('ad_materials as mat', 'mat.id', '=', 'l.ad_material_id')
            ->join('ads as ad', 'ad.id', '=', 'l.ad_id')
            ->join('ad_accounts as acc', 'acc.id', '=', 'ad.ad_account_id')
            ->whereIn('mat.status', AdHealth::NEED_STOP_MATERIAL_STATUSES)
            ->whereNotNull('mat.need_stop_at')
            ->whereIn('ad.status', AdWriteService::ACTIVE_STATUSES)
            ->where(fn ($w) => $this->notStale($w, 'ad.effective_status'))
            ->select(['l.ad_id', 'mat.title'])->orderBy('mat.id');
        if ($f->activeCampaignsOnly) {
            $q->join('ad_campaigns as actv', 'actv.id', '=', 'ad.ad_campaign_id')
                ->whereIn('actv.status', AdWriteService::ACTIVE_STATUSES);
        }
        if ($f->accountIds !== null) {
            $q->whereIn('ad.ad_account_id', $f->accountIds);
        }
        if ($f->platform !== null) {
            $q->where('acc.platform', $f->platform);
        }

        $out = [];
        foreach ($q->get() as $r) {
            $out[(int) $r->ad_id][] = (string) $r->title;
        }

        return $out;
    }
}
