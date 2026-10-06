<?php

namespace App\Ads\Alerts\Rules;

use App\Ads\Alerts\Family;
use App\Ads\Alerts\Finding;
use App\Ads\Alerts\Rule;
use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\Severity;
use App\Models\Ad;
use Carbon\CarbonImmutable;

/**
 * R #6 sales.below_breakeven (daily): 14 mature days (ending today − 2) of a Sales ad; best-of value (Meta purchase value
 * vs real orders net, R 1.3); smoothed ROAS (500-EGP prior toward the account's Sales ROAS) and the plus-one test (one more
 * order of the measured AOV) both below the break-even floor F (BreakEven, D12) → high, Stop.
 */
final class SalesBelowBreakeven implements Rule
{
    public const ID = 'sales.below_breakeven';

    public const WINDOW_DAYS = 14;

    public const LAG_DAYS = 2;

    public const MIN_SPEND = 1000.0;

    public const MIN_DAYS_LIVE = 5;

    public const PRIOR_SPEND = 500.0;

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
        return $now->addHours(72);
    }

    public function evaluate(RuleContext $ctx): array
    {
        $to = $ctx->day(-self::LAG_DAYS);
        $from = $ctx->day(-self::LAG_DAYS - self::WINDOW_DAYS + 1);
        $sales = $ctx->ads->filter(fn (Ad $ad) => $ctx->family($ad) === Family::SALES);
        if ($sales->isEmpty()) {
            return [];
        }

        $be = $ctx->breakEven();
        if ($be['unprofitable']) {
            // Contribution ≤ 0: every order loses money before any ad, so every ad would be "below break-even". One
            // account card asks to check the numbers in Setup instead («الأرقام بتقول إن كل أوردر بيخسر — راجع الإعدادات»).
            $a = $ctx->account;

            return [new Finding(self::ID, Severity::HIGH, 'open_settings', 'account', (int) $a->id, (int) $a->id, null, null, Family::SALES,
                round($sales->keys()->sum(fn (int $id) => $ctx->dailySpend($id)), 2), 'breakeven_unprofitable',
                ['account' => (string) $a->name], ['break_even' => $be, 'sales_ads' => $sales->count()])];
        }
        $floor = (float) $be['floor'];
        $aov = (float) ($be['aov'] ?? 0);
        $minSpend = max(self::MIN_SPEND, 2 * (float) ($ctx->targets()['cpp'] ?? 0));
        $orders = $ctx->data->realOrders($sales->keys()->all(), $from, $to);

        $sumSpend = 0.0;
        $sumValue = 0.0;
        foreach ($sales->keys() as $id) {
            $s = $ctx->sumOf($id, $from, $to);
            $sumSpend += $s['spend'];
            $sumValue += max($s['purchase_value'], $orders[$id]['net'] ?? 0);
        }
        $accountRoas = $sumSpend > 0 ? $sumValue / $sumSpend : $floor;

        $out = [];
        foreach ($sales as $id => $ad) {
            $s = $ctx->sumOf($id, $from, $to);
            if ($s['spend'] < $minSpend || $s['days_live'] < self::MIN_DAYS_LIVE || $ctx->isLearning($id) || $ctx->barelyDelivered($ad, $from, $to)) {
                continue;
            }
            $crm = (float) ($orders[$id]['net'] ?? 0);
            $best = max($s['purchase_value'], $crm);
            $smoothed = ($best + $accountRoas * self::PRIOR_SPEND) / ($s['spend'] + self::PRIOR_SPEND);
            $plusOne = ($best + $aov) / $s['spend'];
            if ($smoothed >= $floor || $plusOne >= $floor) {
                continue;
            }
            $roas = $best / $s['spend'];

            $out[] = Finding::forAd(self::ID, $ad, Family::SALES, Severity::HIGH, 'stop',
                round($s['spend'] / $s['days_live'] * max(0.0, 1 - $roas / $floor), 2), 'below_breakeven',
                ['roas' => round($roas, 2), 'roas_meta' => round($s['purchase_value'] / $s['spend'], 2), 'roas_crm' => round($crm / $s['spend'], 2),
                    'floor' => $floor, 'floor_default' => (bool) $be['is_default']],
                ['window' => ['from' => $from, 'to' => $to], 'attribution' => 'best_of', 'smoothed' => round($smoothed, 3),
                    'plus_one' => round($plusOne, 3), 'account_roas' => round($accountRoas, 3), 'aov' => $aov, 'spend' => $s['spend'], 'break_even' => $be]);
        }

        return $out;
    }
}
