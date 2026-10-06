<?php

namespace App\Ads\Alerts\Rules;

use App\Ads\Alerts\Family;
use App\Ads\Alerts\Finding;
use App\Ads\Alerts\Rule;
use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\Severity;
use Carbon\CarbonImmutable;

/**
 * R #5 all.spend_no_result (daily, R-01): spend over the last 14 complete days with zero results counted through today
 * (lag days and today only add results). k = spend / reference cost; k ≥ 2 → medium, k ≥ 3 → high; spend ≥ the hard cap
 * (max(1,500, 4 × order target)) → high with no reference needed. Exempt from the learning gate; says "still learning" then.
 */
final class SpendNoResult implements Rule
{
    public const ID = 'all.spend_no_result';

    public const WINDOW_DAYS = 14;

    public const WARN_K = 2.0;

    public const STOP_K = 3.0;

    public const MIN_SPEND = 500.0;

    public const HARD_CAP = 1500.0;

    public const MIN_DAYS = [Family::SALES => 3, Family::MESSAGES => 4];

    public function id(): string
    {
        return self::ID;
    }

    public function schedule(): string
    {
        return self::DAILY;
    }

    public function isPerformance(): bool
    {
        return true;
    }

    public function cooldownUntil(CarbonImmutable $now): CarbonImmutable
    {
        return $now->addHours(48);
    }

    public function evaluate(RuleContext $ctx): array
    {
        $from = $ctx->day(-self::WINDOW_DAYS);
        $to = $ctx->day(-1);
        $today = $ctx->day(0);
        $targets = $ctx->targets();
        $orders = $ctx->data->realOrders($ctx->ads->keys()->all(), $from, $today);
        $cohort = $ctx->data->cohortChats($ctx->ads->pluck('external_id')->map(fn ($x) => (string) $x)->all(), $from, $today);

        $out = [];
        foreach ($ctx->ads as $id => $ad) {
            $family = $ctx->family($ad);
            if (! isset(self::MIN_DAYS[$family])) {
                continue;
            }
            $w = $ctx->sumOf($id, $from, $to);
            if ($w['spend'] <= 0) {
                continue;
            }
            $through = $ctx->sumOf($id, $from, $today);
            $results = $family === Family::SALES
                ? max($through['purchases'], $orders[$id]['count'] ?? 0)
                : max($through['msg_conversations'], $cohort[(string) $ad->external_id] ?? 0);
            if ($results > 0 || $ctx->barelyDelivered($ad, $from, $to)) {
                continue;
            }

            $ref = $family === Family::SALES ? $targets['cpp'] : $targets['cpc'];
            $orderTarget = $family === Family::SALES ? $targets['cpp'] : $targets['cpo'];
            $cap = max(self::HARD_CAP, 4 * (float) ($orderTarget ?? 0));
            $minSpend = max(self::MIN_SPEND, 1.5 * (float) ($ref ?? 0));
            $k = $ref !== null && $ref > 0 ? $w['spend'] / $ref : null;
            $overCap = $w['spend'] >= $cap;
            $byMultiple = $k !== null && $k >= self::WARN_K && $w['spend'] >= $minSpend && $w['days_live'] >= self::MIN_DAYS[$family];
            if (! $overCap && ! $byMultiple) {
                continue;
            }

            $learning = $ctx->isLearning($id);
            $high = $overCap || $k >= self::STOP_K;
            $key = $byMultiple ? ($learning ? 'spend_no_result_learning' : 'spend_no_result') : 'spend_no_result_cap';

            $out[] = Finding::forAd(self::ID, $ad, $family, $high ? Severity::HIGH : Severity::MEDIUM, 'stop',
                round($w['spend'] / max(1, $w['days_live']), 2), $key,
                ['spend' => round($w['spend']), 'k' => $k === null ? null : round($k, 1), 'days' => $w['days_live'],
                    'result' => $family === Family::SALES ? 'purchase' : 'chat', 'cap' => round($cap)],
                ['window' => ['from' => $from, 'to' => $to, 'results_through' => $today],
                    'reference' => ['value' => $ref, 'source' => $family === Family::SALES ? $targets['cpp_source'] : 'median'],
                    'hard_cap' => $cap, 'min_spend' => $minSpend, 'learning' => $learning, 'results' => 0]);
        }

        return $out;
    }
}
