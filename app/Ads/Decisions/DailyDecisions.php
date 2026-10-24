<?php

namespace App\Ads\Decisions;

use App\Ads\AdsSettings;
use App\Ads\Control\AdWriteService;
use App\Ads\Control\StopAdvisor;
use App\Ads\Materials\StockWatcher;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\WinnerScorer;
use App\Models\AdAccount;
use App\Models\User;

/**
 * «قرار اليوم» (round 3, 2026-10-24): one decision per running ad the scorer can judge, with the evidence line and the
 * action «نفّذ» will send through the write pipeline:
 *
 *   stock_risk    the ad's product is out or about to run out (StockWatcher::riskRows) — pause (a fact, judged first);
 *   cut           loser tier (ROAS under the loser threshold with real spend) or spend without a purchase (StopAdvisor) — pause;
 *   fix_creative  creative fatigue (CTR drop / frequency, AdInsights) — pause and give the content team the material;
 *   scale         winner tier with a trend that is not falling — raise the daily budget by scale_pct;
 *   keep          everything else that passed the gate — no action.
 *
 * Numbers come from the same WinnerScorer window every other screen uses (14 days ending today, DecisionCounter::window),
 * so a decision never disagrees with the Explorer or the drawer. Nothing here calls a platform.
 */
final class DailyDecisions
{
    public const KINDS = ['stock_risk', 'cut', 'fix_creative', 'scale', 'keep'];

    public const SCALE_PCT_SETTING = 'scale_pct';

    public function __construct(
        private readonly WinnerScorer $scorer,
        private readonly StopAdvisor $advisor,
        private readonly StockWatcher $stock,
        private readonly AdWriteService $writes,
        private readonly AdsSettings $settings,
    ) {}

    /** The percentage the «scale» decision raises a winner's daily budget by (ads_settings.scale_pct, else crm.ads.scale_pct). */
    public function scalePct(): int
    {
        $pct = (int) $this->settings->get(self::SCALE_PCT_SETTING, config('crm.ads.scale_pct', 20));

        return max(1, min(200, $pct));
    }

    /**
     * @return list<array<string, mixed>> decisions in the day's order (stock risk, cut, fix creative, scale, keep)
     */
    public function build(User $u, AdsFilter $f): array
    {
        $window = DecisionCounter::window($f);
        if ($window->isEmpty()) {
            return [];
        }
        $pct = $this->scalePct();
        $decisions = [];

        foreach ($this->scorer->build($window, 'active') as $row) {
            $ad = $row['ad'];
            if (AdWriteService::statusKind($ad['status'] ?? null) !== 'active' || in_array(strtoupper((string) ($ad['effective_status'] ?? '')), StopAdvisor::STALE_STATUSES, true)) {
                continue;
            }
            $reasons = $row['reasons'];
            $byKey = collect($reasons)->keyBy('key');
            [$kind, $action] = match (true) {
                $row['tier'] === 'loser' => ['cut', ['type' => 'set_status', 'to' => 'paused']],
                (bool) ($row['fatigue']['flag'] ?? false) => ['fix_creative', ['type' => 'set_status', 'to' => 'paused']],
                $row['tier'] === 'winner' && ($row['trend']['dir'] ?? 'flat') !== 'down' => ['scale', ['type' => 'set_budget', 'pct' => $pct]],
                default => ['keep', null],
            };
            if ($kind === 'cut' && ! $byKey->has('roas_below')) {
                $reasons[] = ['key' => 'roas_below', 'params' => ['roas' => $row['roas'], 'threshold' => $this->settings->winnerThresholds()['loser']]];
            }
            if ($kind === 'scale' && ! $byKey->has('scale_winner')) {
                $reasons[] = ['key' => 'scale_winner', 'params' => ['pct' => $pct, 'roas' => $row['roas']]];
            }

            $decisions[(int) $ad['id']] = [
                'key' => 'ad:'.$ad['id'],
                'kind' => $kind,
                'action' => $action,
                'ad' => [
                    'id' => (int) $ad['id'], 'external_id' => (string) $ad['external_id'], 'name' => (string) $ad['name'],
                    'account_id' => (int) $ad['account_id'], 'account' => (string) $ad['account'], 'platform' => (string) $ad['platform'],
                    'status' => $ad['status'], 'effective_status' => $ad['effective_status'] ?? null, 'thumbnail_url' => $ad['thumbnail_url'] ?? null,
                    'campaign' => $ad['campaign'] ?? null, 'objective' => $ad['objective'] ?? null, 'currency' => $ad['currency'] ?? 'EGP',
                    'parent_paused' => (bool) ($ad['parent_paused'] ?? false),
                ],
                'evidence' => [
                    'spend' => (float) $ad['spend_tax'], 'spend_pre_tax' => (float) $row['spend'],
                    'results' => (float) $row['orders'], 'real_orders' => (int) ($ad['real_orders'] ?? 0), 'conversations' => (int) ($ad['conversations'] ?? 0),
                    'revenue' => (float) $row['revenue'], 'real_revenue' => (float) ($ad['real_revenue'] ?? 0),
                    'roas' => $row['roas'], 'real_roas' => $ad['real_roas'] ?? null, 'cac' => $ad['cac'] ?? null,
                    'trend' => $row['trend'], 'fatigue' => $row['fatigue'], 'days' => (int) $row['active_days'],
                    'tier' => $row['tier'], 'score' => (int) $row['score'],
                ],
                'reasons' => $reasons,
                'spend_today' => (float) ($ad['spend_today'] ?? 0),
                'stock' => null,
            ];
        }

        // Spend without a purchase (StopAdvisor, a cut) for ads the scorer gate left out or judged neutral.
        foreach ($this->advisor->suggest($window) as $s) {
            $keys = collect($s['reasons'])->pluck('key');
            if (! $keys->contains('no_purchases') && ! $keys->contains('need_stop')) {
                continue;
            }
            $id = (int) $s['ad_id'];
            if (isset($decisions[$id])) {
                if ($decisions[$id]['kind'] === 'keep') {
                    $decisions[$id]['kind'] = 'cut';
                    $decisions[$id]['action'] = ['type' => 'set_status', 'to' => 'paused'];
                }
                $decisions[$id]['reasons'] = $this->mergeReasons($decisions[$id]['reasons'], $s['reasons']);

                continue;
            }
            $decisions[$id] = [
                'key' => 'ad:'.$id, 'kind' => 'cut', 'action' => ['type' => 'set_status', 'to' => 'paused'],
                'ad' => [
                    'id' => $id, 'external_id' => $s['external_id'], 'name' => $s['name'], 'account_id' => (int) $s['account_id'], 'account' => $s['account'],
                    'platform' => $s['platform'], 'status' => $s['status'], 'effective_status' => null, 'thumbnail_url' => $s['thumbnail_url'],
                    'campaign' => $s['campaign'], 'objective' => $s['objective'], 'currency' => 'EGP', 'parent_paused' => false,
                ],
                'evidence' => [
                    'spend' => (float) $s['spend_tax'], 'spend_pre_tax' => (float) $s['spend'], 'results' => 0.0, 'real_orders' => 0, 'conversations' => 0,
                    'revenue' => 0.0, 'real_revenue' => 0.0, 'roas' => $s['roas'], 'real_roas' => null, 'cac' => null,
                    'trend' => ['roas_pct' => null, 'spend_pct' => null, 'dir' => 'flat'], 'fatigue' => ['flag' => false, 'ctr_drop_pct' => null, 'frequency' => null],
                    'days' => DecisionCounter::SUGGEST_DAYS, 'tier' => null, 'score' => null,
                ],
                'reasons' => $s['reasons'], 'spend_today' => (float) $s['spend_today'], 'stock' => null,
            ];
        }

        // Stock is a fact: a running ad whose product is low or out is a stock risk whatever the numbers said.
        foreach ($this->stock->riskRows($f) as $r) {
            $id = (int) $r['ad_id'];
            $stock = ['state' => $r['stock'], 'inventory' => (int) $r['inventory'], 'threshold' => (int) $r['threshold'], 'product' => $r['product'], 'material' => $r['material']];
            $reason = ['key' => $r['stock'] === 'out' ? 'need_stop' : 'stock_low', 'params' => ['material' => $r['material'], 'product' => $r['product'], 'units' => (int) $r['inventory']]];
            if (isset($decisions[$id])) {
                $decisions[$id]['kind'] = 'stock_risk';
                $decisions[$id]['action'] = ['type' => 'set_status', 'to' => 'paused'];
                $decisions[$id]['stock'] = $stock;
                $decisions[$id]['reasons'] = $this->mergeReasons([$reason], $decisions[$id]['reasons']);

                continue;
            }
            $decisions[$id] = [
                'key' => 'ad:'.$id, 'kind' => 'stock_risk', 'action' => ['type' => 'set_status', 'to' => 'paused'],
                'ad' => [
                    'id' => $id, 'external_id' => $r['external_id'], 'name' => $r['name'], 'account_id' => (int) $r['account_id'], 'account' => $r['account'],
                    'platform' => $r['platform'], 'status' => $r['status'], 'effective_status' => null, 'thumbnail_url' => $r['thumbnail_url'],
                    'campaign' => null, 'objective' => null, 'currency' => 'EGP', 'parent_paused' => false,
                ],
                'evidence' => [
                    'spend' => 0.0, 'spend_pre_tax' => 0.0, 'results' => 0.0, 'real_orders' => 0, 'conversations' => 0, 'revenue' => 0.0, 'real_revenue' => 0.0,
                    'roas' => null, 'real_roas' => null, 'cac' => null, 'trend' => ['roas_pct' => null, 'spend_pct' => null, 'dir' => 'flat'],
                    'fatigue' => ['flag' => false, 'ctr_drop_pct' => null, 'frequency' => null], 'days' => 0, 'tier' => null, 'score' => null,
                ],
                'reasons' => [$reason], 'spend_today' => 0.0, 'stock' => $stock,
            ];
        }

        if ($decisions === []) {
            return [];
        }

        $accounts = AdAccount::query()->whereIn('id', array_unique(array_map(fn (array $d) => $d['ad']['account_id'], $decisions)))
            ->get(['id', 'is_active', 'write_enabled', 'platform', 'external_id']);
        $can = $this->writes->canWriteMany($u, $accounts);
        $rank = array_flip(self::KINDS);
        $list = array_values(array_map(fn (array $d) => $d + ['can_write' => (bool) ($can[$d['ad']['account_id']] ?? false)], $decisions));
        usort($list, fn (array $a, array $b) => [$rank[$a['kind']], -$a['evidence']['spend'], $a['ad']['id']] <=> [$rank[$b['kind']], -$b['evidence']['spend'], $b['ad']['id']]);

        return $list;
    }

    /**
     * @param  list<array<string, mixed>>  $list
     * @return array<string, int> per kind, every kind present
     */
    public static function counts(array $list): array
    {
        $out = array_fill_keys(self::KINDS, 0);
        foreach ($list as $d) {
            $out[$d['kind']] = ($out[$d['kind']] ?? 0) + 1;
        }

        return $out;
    }

    /** @return list<array{key: string, params: array}> first list first, no duplicate keys */
    private function mergeReasons(array $first, array $second): array
    {
        $seen = [];
        $out = [];
        foreach ([...$first, ...$second] as $r) {
            if (isset($seen[$r['key']])) {
                continue;
            }
            $seen[$r['key']] = true;
            $out[] = $r;
        }

        return $out;
    }
}
