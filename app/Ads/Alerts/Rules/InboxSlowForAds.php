<?php

namespace App\Ads\Alerts\Rules;

use App\Ads\Alerts\Family;
use App\Ads\Alerts\Finding;
use App\Ads\Alerts\Rule;
use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\Severity;
use App\Ads\Alerts\Stats;
use Carbon\CarbonImmutable;

/**
 * R #12 msg.inbox_slow_for_ads (daily, owner): yesterday ≥ 20 chats came from this account's ads and the median first
 * reply (user or bot, A4) took > 10 min or > 25 % got no reply within an hour: we pay for chats we lose.
 */
final class InboxSlowForAds implements Rule
{
    public const ID = 'msg.inbox_slow_for_ads';

    public const MIN_CHATS = 20;

    public const SLOW_MEDIAN_MIN = 10;

    public const UNANSWERED_SHARE = 0.25;

    public const UNANSWERED_AFTER_MIN = 60;

    private const NEVER = 1.0e9;

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
        return $now->addHours(20);
    }

    public function evaluate(RuleContext $ctx): array
    {
        $y = $ctx->day(-1);
        $external = $ctx->data->accountAds($ctx->account->id)->pluck('external_id')->map(fn ($x) => (string) $x)->all();
        $waits = $ctx->data->firstReplyWaits($external, $y, $y);
        $n = count($waits);
        if ($n < self::MIN_CHATS) {
            return [];
        }

        $unanswered = count(array_filter($waits, fn (?float $w) => $w === null || $w > self::UNANSWERED_AFTER_MIN));
        $share = $unanswered / $n;
        $median = (float) Stats::median(array_map(fn (?float $w) => $w ?? self::NEVER, $waits));
        if ($median <= self::SLOW_MEDIAN_MIN && $share <= self::UNANSWERED_SHARE) {
            return [];
        }

        $spend = 0.0;
        foreach ($ctx->ads as $id => $ad) {
            if ($ctx->family($ad) === Family::MESSAGES) {
                $spend += $ctx->sumOf($id, $y, $y)['spend'];
            }
        }
        $a = $ctx->account;

        return [new Finding(self::ID, Severity::HIGH, 'open_queue', 'account', (int) $a->id, (int) $a->id, null, null, Family::MESSAGES,
            round($spend * $share, 2), 'inbox_slow_for_ads',
            ['account' => (string) $a->name, 'share' => (int) round($share * 100), 'minutes' => (int) round(min($median, self::UNANSWERED_AFTER_MIN)), 'chats' => $n],
            ['day' => $y, 'median_minutes' => $median >= self::NEVER ? null : $median, 'unanswered_share' => round($share, 3), 'chats' => $n, 'messages_spend' => $spend])];
    }
}
