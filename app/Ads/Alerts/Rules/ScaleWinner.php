<?php

namespace App\Ads\Alerts\Rules;

use App\Ads\Alerts\ChatSignals;
use App\Ads\Alerts\Family;
use App\Ads\Alerts\Finding;
use App\Ads\Alerts\Rule;
use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\Severity;
use App\Models\Ad;
use App\Models\AdsAlert;
use Carbon\CarbonImmutable;

/**
 * R #17 rec.scale_winner (daily, information only): a proven winner judged on the WORSE of Meta and real orders (R 1.3:
 * Meta over-claims, so scaling must survive the pessimistic number). Sales: worst-of ROAS ≥ 1.4 F on the last 7 and 14
 * mature days and ≥ 15 worst-of results; Messages: cost per chat order ≤ 0.8 T on both windows, ≥ 10 chat orders.
 */
final class ScaleWinner implements Rule
{
    public const ID = 'rec.scale_winner';

    public const MULTIPLE = 1.4;

    public const MIN_RESULTS = 15;

    public const MIN_CHAT_ORDERS = 10;

    public const CPO_SHARE = 0.8;

    public function __construct(private readonly ChatSignals $chats) {}

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
        return $now->addDays(7);
    }

    public function evaluate(RuleContext $ctx): array
    {
        $blocked = AdsAlert::query()->whereIn('ad_id', $ctx->ads->keys())->whereIn('state', AdsAlert::LIVE_STATES)
            ->whereIn('severity', [Severity::CRITICAL, Severity::HIGH])->pluck('ad_id')->map(fn ($id) => (int) $id)->all();
        $candidates = $ctx->ads->reject(fn (Ad $ad) => in_array($ad->id, $blocked, true));
        $be = $ctx->breakEven();

        return [...$this->sales($ctx, $candidates->filter(fn (Ad $ad) => $ctx->family($ad) === Family::SALES), $be),
            ...$this->messages($ctx, $candidates->filter(fn (Ad $ad) => $ctx->family($ad) === Family::MESSAGES))];
    }

    /** @return list<Finding> */
    private function sales(RuleContext $ctx, $ads, array $be): array
    {
        if ($ads->isEmpty()) {
            return [];
        }
        [$f14, $t] = [$ctx->day(-15), $ctx->day(-2)];
        $f7 = $ctx->day(-8);
        $o14 = $ctx->data->realOrders($ads->keys()->all(), $f14, $t);
        $o7 = $ctx->data->realOrders($ads->keys()->all(), $f7, $t);
        $bar = self::MULTIPLE * (float) $be['floor'];

        $out = [];
        foreach ($ads as $id => $ad) {
            $s14 = $ctx->sumOf($id, $f14, $t);
            $s7 = $ctx->sumOf($id, $f7, $t);
            if ($s14['spend'] <= 0 || $s7['spend'] <= 0 || $ctx->barelyDelivered($ad, $f14, $t)) {
                continue;
            }
            $worst14 = min($s14['purchase_value'], $o14[$id]['net'] ?? 0) / $s14['spend'];
            $worst7 = min($s7['purchase_value'], $o7[$id]['net'] ?? 0) / $s7['spend'];
            $results = min($s14['purchases'], $o14[$id]['count'] ?? 0);
            if ($worst14 < $bar || $worst7 < $bar || $results < self::MIN_RESULTS) {
                continue;
            }
            $out[] = Finding::forAd(self::ID, $ad, Family::SALES, Severity::INFO, 'none', 0.0, 'scale_winner',
                ['days' => 7, 'roas' => round($worst14, 2), 'floor' => (float) $be['floor'], 'floor_default' => (bool) $be['is_default']],
                ['attribution' => 'worst_of', 'roas_7' => round($worst7, 2), 'roas_meta' => round($s14['purchase_value'] / $s14['spend'], 2),
                    'roas_crm' => round(($o14[$id]['net'] ?? 0) / $s14['spend'], 2), 'results' => $results],
                null, 'recommendation');
        }

        return $out;
    }

    /** @return list<Finding> */
    private function messages(RuleContext $ctx, $ads): array
    {
        $target = $ctx->targets()['cpo'];
        if ($ads->isEmpty() || $target === null || $target <= 0) {
            return [];
        }
        [$f14, $t] = [$ctx->day(-16), $ctx->day(-3)];
        $f7 = $ctx->day(-9);
        $c14 = $this->chats->forAds($ads->keys()->all(), $f14, $t);
        $c7 = $this->chats->forAds($ads->keys()->all(), $f7, $t);

        $out = [];
        foreach ($ads as $id => $ad) {
            $orders14 = (int) ($c14[$id]['orders'] ?? 0);
            $orders7 = (int) ($c7[$id]['orders'] ?? 0);
            if ($orders14 < self::MIN_CHAT_ORDERS || $orders7 < 1 || $ctx->barelyDelivered($ad, $f14, $t)) {
                continue;
            }
            $cpo14 = $ctx->sumOf($id, $f14, $t)['spend'] / $orders14;
            $cpo7 = $ctx->sumOf($id, $f7, $t)['spend'] / $orders7;
            if ($cpo14 > self::CPO_SHARE * $target || $cpo7 > self::CPO_SHARE * $target) {
                continue;
            }
            $out[] = Finding::forAd(self::ID, $ad, Family::MESSAGES, Severity::INFO, 'none', 0.0, 'scale_winner_msg',
                ['cpo' => (int) round($cpo14), 'target' => (int) round($target), 'orders' => $orders14],
                ['attribution' => 'chat_orders', 'cpo_7' => round($cpo7, 2)], null, 'recommendation');
        }

        return $out;
    }
}
