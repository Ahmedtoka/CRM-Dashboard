<?php

namespace App\Channels\Adapters\TikTok;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\SenderType;
use App\Models\Conversation;
use Carbon\CarbonImmutable;

/**
 * TikTok's messaging limits (Business Messaging API «Messaging limits», read 2026-10-24), derived from the
 * thread itself so nothing has to be counted separately:
 *
 *  - the business may never write first;
 *  - after the customer's first message: at most FIRST_WINDOW_CAP messages within WINDOW_HOURS, until she
 *    replies to one of ours;
 *  - once she has replied to us: unlimited for WINDOW_HOURS after each of her messages;
 *  - more than WINDOW_HOURS after her last message: AFTER_WINDOW_CAP more messages until she writes again.
 *
 * Outbound rows that count: bot and staff messages (not system lines) that were not refused, created after
 * her last message. Mirrors the rule the bot and queue see through WindowPolicy (LimitsOutbound).
 */
final class TikTokWindow
{
    public const WINDOW_HOURS = 48;

    public const FIRST_WINDOW_CAP = 10;

    public const AFTER_WINDOW_CAP = 3;

    /** @return int|null messages still allowed now; null = unlimited at the moment */
    public static function remaining(Conversation $c): ?int
    {
        $last = $c->last_customer_message_at ? CarbonImmutable::instance($c->last_customer_message_at) : null;

        if ($last === null) {
            return 0;
        }

        $sentSince = self::outbound($c)->where('created_at', '>', $last)->count();
        $inWindow = CarbonImmutable::now()->lessThanOrEqualTo($last->addHours(self::WINDOW_HOURS));

        if (! $inWindow) {
            return max(0, self::AFTER_WINDOW_CAP - $sentSince);
        }

        // Her last message came after one of ours: she replied to us, the window is unlimited.
        $replied = self::outbound($c)->where('created_at', '<', $last)->exists();

        return $replied ? null : max(0, self::FIRST_WINDOW_CAP - $sentSince);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<\App\Models\Message> */
    private static function outbound(Conversation $c)
    {
        return $c->messages()
            ->where('direction', MessageDirection::Out->value)
            ->whereIn('sender_type', [SenderType::User->value, SenderType::Bot->value])
            ->where('status', '!=', MessageStatus::Failed->value);
    }
}
