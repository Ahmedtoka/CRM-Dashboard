<?php

namespace App\Ads\Alerts\Rules;

use App\Ads\Alerts\Finding;
use App\Ads\Alerts\Rule;
use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\Severity;
use Carbon\CarbonImmutable;

/** R #16 all.high_refusal (daily): ≥ 10 finished COD orders in 30 days, ≥ 30 % refused/returned and ≥ 1.5 × the account rate. */
final class HighRefusal implements Rule
{
    public const ID = 'all.high_refusal';

    public const MIN_ORDERS = 10;

    public const MIN_SHARE = 0.30;

    public const ACCOUNT_MULTIPLE = 1.5;

    public const WINDOW_DAYS = 30;

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
        return false;
    }

    public function cooldownUntil(CarbonImmutable $now): CarbonImmutable
    {
        return $now->addDays(7);
    }

    public function evaluate(RuleContext $ctx): array
    {
        $stats = $ctx->data->shipmentOutcomes($ctx->ads->keys()->all(), $ctx->day(-self::WINDOW_DAYS), $ctx->day(-1));
        if ($stats === []) {
            return [];
        }
        $accountRate = (float) $ctx->breakEven()['refusal_rate'];

        $out = [];
        foreach ($stats as $adId => $s) {
            if ($s['terminal'] < self::MIN_ORDERS) {
                continue;
            }
            $share = $s['refused'] / $s['terminal'];
            if ($share < self::MIN_SHARE || $share < self::ACCOUNT_MULTIPLE * $accountRate) {
                continue;
            }
            $ad = $ctx->ads[$adId];
            $out[] = Finding::forAd(self::ID, $ad, $ctx->family($ad), Severity::MEDIUM, 'view_orders',
                round($ctx->dailySpend($adId) * $share, 2), 'high_refusal',
                ['share' => (int) round($share * 100), 'avg' => (int) round($accountRate * 100), 'orders' => $s['terminal']],
                ['window_days' => self::WINDOW_DAYS, 'refused' => $s['refused'], 'terminal' => $s['terminal'], 'account_rate' => $accountRate]);
        }

        return $out;
    }
}
