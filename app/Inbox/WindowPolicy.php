<?php

namespace App\Inbox;

use App\Channels\ChannelRegistry;
use App\Channels\Contracts\LimitsOutbound;
use App\Enums\SenderType;
use App\Models\Conversation;
use Carbon\CarbonImmutable;

/**
 * Platform reply-window rules (spec §5.6), driven by adapter capabilities.
 */
class WindowPolicy
{
    public function __construct(private readonly ChannelRegistry $registry) {}

    public function evaluate(Conversation $c, SenderType $sender): WindowState
    {
        $adapter = $this->registry->adapter($c->platform);
        $caps = $adapter->capabilities();
        $now = CarbonImmutable::now();

        // TikTok (x6, 2026-10-24): 10 messages before her first reply, 3 after 48 h of silence. Nothing left = closed,
        // whatever the hours say, so the bot and the queue meet the WindowClosedException they already handle.
        if ($adapter instanceof LimitsOutbound && $adapter->outboundAllowance($c) === 0) {
            return new WindowState(WindowState::CLOSED, null);
        }

        $last = $c->last_customer_message_at
            ? CarbonImmutable::instance($c->last_customer_message_at)
            : null;

        if ($last !== null) {
            $windowEnd = $last->addHours($caps->windowHours);

            if ($now->lessThanOrEqualTo($windowEnd)) {
                return new WindowState(WindowState::OPEN, $windowEnd);
            }
        }

        // Outside (or without) the free-form window only humans have options.
        if ($sender !== SenderType::User) {
            return new WindowState(WindowState::CLOSED, null);
        }

        if ($caps->templatesOutsideWindow) {
            return new WindowState(WindowState::TEMPLATE_ONLY, null);
        }

        if ($last !== null && $caps->humanAgentHours > 0) {
            $humanAgentEnd = $last->addHours($caps->humanAgentHours);

            if ($now->lessThanOrEqualTo($humanAgentEnd)) {
                return new WindowState(WindowState::HUMAN_AGENT, $humanAgentEnd);
            }
        }

        return new WindowState(WindowState::CLOSED, null);
    }
}
