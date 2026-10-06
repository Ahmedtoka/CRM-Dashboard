<?php

namespace App\Ads\Alerts\Rules;

use App\Ads\Alerts\ChatSignals;
use App\Ads\Alerts\Finding;
use App\Ads\Alerts\Rule;
use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\Severity;
use App\Inbox\Outcomes\Outcome;
use Carbon\CarbonImmutable;

/** Chat outcome rule (spec 7.2): > 25 % of an ad's chats (S3 outcomes, last 14 days, ≥ 20 chats) ended on "size out". */
final class ChatSizeOutShare implements Rule
{
    public const ID = 'chat.size_out_share';

    public const MIN_CHATS = 20;

    public const SHARE = 0.25;

    public const WINDOW_DAYS = 14;

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
        return false;
    }

    public function cooldownUntil(CarbonImmutable $now): CarbonImmutable
    {
        return $now->addDays(7);
    }

    public function evaluate(RuleContext $ctx): array
    {
        $rows = $this->chats->forAds($ctx->ads->keys()->all(), $ctx->day(-self::WINDOW_DAYS), $ctx->day(-1));

        $out = [];
        foreach ($rows as $adId => $row) {
            $chats = (int) ($row['chats'] ?? 0);
            $count = (int) ($row['reasons'][Outcome::SizeOut->value] ?? 0);
            if ($chats < self::MIN_CHATS || $count / $chats <= self::SHARE || ! $ctx->ads->has($adId)) {
                continue;
            }
            $share = $count / $chats;
            $ad = $ctx->ads[$adId];
            $out[] = Finding::forAd(self::ID, $ad, $ctx->family($ad), Severity::MEDIUM, 'check_stock',
                round($ctx->dailySpend($adId) * $share, 2), 'chat_size_out',
                ['share' => (int) round($share * 100), 'chats' => $chats], ['reasons' => $row['reasons'] ?? [], 'window_days' => self::WINDOW_DAYS]);
        }

        return $out;
    }
}
