<?php

namespace App\Ads\Alerts\Rules;

use App\Ads\Alerts\ChatSignals;
use App\Ads\Alerts\Family;
use App\Ads\Alerts\Finding;
use App\Ads\Alerts\Rule;
use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\Severity;
use App\Models\Ad;
use Carbon\CarbonImmutable;

/**
 * R #8 msg.chats_no_orders (daily): a Messages ad brought ≥ 15 chats in 14 mature days (ending today − 3) and no chat
 * order while spending ≥ 2 × (medium) / 3 × (high) the target cost per chat order. Silent when the account's own
 * chat→order rate fell ≥ 25 % against the previous 14 days (the inbox, not the ad, is the problem).
 */
final class ChatsNoOrders implements Rule
{
    public const ID = 'msg.chats_no_orders';

    public const WINDOW_DAYS = 14;

    public const LAG_DAYS = 3;

    public const MIN_CHATS = 15;

    public const MED_K = 2.0;

    public const HIGH_K = 3.0;

    public const ACCOUNT_DROP = 0.25;

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
        return $now->addHours(72);
    }

    public function evaluate(RuleContext $ctx): array
    {
        $target = $ctx->targets()['cpo'];
        $messages = $ctx->ads->filter(fn (Ad $ad) => $ctx->family($ad) === Family::MESSAGES);
        if ($target === null || $target <= 0 || $messages->isEmpty()) {
            return [];
        }

        $to = $ctx->day(-self::LAG_DAYS);
        $from = $ctx->day(-self::LAG_DAYS - self::WINDOW_DAYS + 1);
        $ids = $messages->keys()->all();
        $current = $this->chats->forAds($ids, $from, $to);
        $start = CarbonImmutable::parse($from);
        $previous = $this->chats->forAds($ids, $start->subDays(self::WINDOW_DAYS)->toDateString(), $start->subDay()->toDateString());

        $rate = function (array $rows): ?float {
            $chats = array_sum(array_column($rows, 'chats'));

            return $chats > 0 ? array_sum(array_column($rows, 'orders')) / $chats : null;
        };
        $now = $rate($current);
        $before = $rate($previous);
        if ($now !== null && $before !== null && $before > 0 && ($before - $now) / $before >= self::ACCOUNT_DROP) {
            return [];
        }

        $out = [];
        foreach ($messages as $id => $ad) {
            $f = array_merge(ChatSignals::empty(), $current[$id] ?? []);
            if ($f['chats'] < self::MIN_CHATS || $f['orders'] > 0) {
                continue;
            }
            $s = $ctx->sumOf($id, $from, $to);
            $k = $s['spend'] / $target;
            if ($k < self::MED_K || $ctx->barelyDelivered($ad, $from, $to)) {
                continue;
            }

            $out[] = Finding::forAd(self::ID, $ad, Family::MESSAGES, $k >= self::HIGH_K ? Severity::HIGH : Severity::MEDIUM, 'stop',
                round($s['spend'] / self::WINDOW_DAYS, 2), 'chats_no_orders',
                ['chats' => (int) $f['chats'], 'days' => self::WINDOW_DAYS, 'k' => round($k, 1), 'spend' => round($s['spend'])],
                ['window' => ['from' => $from, 'to' => $to], 'target_cpo' => $target, 'funnel' => $f, 'account_rate' => $now, 'account_rate_before' => $before]);
        }

        return $out;
    }
}
