<?php

namespace App\Inbox;

use App\Channels\ChannelRegistry;
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
        $caps = $this->registry->adapter($c->platform)->capabilities();
        $now = CarbonImmutable::now();
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
