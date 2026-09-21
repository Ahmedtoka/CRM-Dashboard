<?php

namespace App\Bot\Flows;

use App\Bot\WorkingHours;
use App\Enums\SenderType;
use App\Inbox\OutboundService;
use App\Inbox\WindowClosedException;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * She has been handed to the team and keeps writing while she waits (owner, 2026-09-21).
 * The bot reassures her once, then at most once every `crm.bot.waiting_ack_minutes`, and
 * never after an agent has actually answered — silence while she writes reads as neglect,
 * but a line on every message reads like a machine.
 */
class WaitingReply
{
    public const STATE_KEY = 'waiting_ack_at';

    public function __construct(
        private readonly OutboundService $outbound,
        private readonly FlowPrompter $prompter,
    ) {}

    /** True when a reassurance was sent for this conversation now. */
    public function maybeSend(Conversation $c, ?CarbonImmutable $at = null): bool
    {
        $at ??= CarbonImmutable::now();

        if (! $c->needs_human || $c->handover_at === null) {
            return false;
        }

        // An agent already answered: she is in a real conversation, not waiting.
        $answered = Message::where('conversation_id', $c->id)
            ->where('sender_type', SenderType::User->value)
            ->where('created_at', '>=', $c->handover_at)
            ->exists();

        if ($answered) {
            return false;
        }

        $cooldown = max(1, (int) config('crm.bot.waiting_ack_minutes', 15));
        $last = $c->bot_state[self::STATE_KEY] ?? null;

        if (is_string($last) && CarbonImmutable::parse($last)->addMinutes($cooldown)->isAfter($at)) {
            return false;
        }

        $text = $this->text($at);

        if ($text === null) {
            return false;
        }

        try {
            $this->outbound->sendBot($c, $text);
        } catch (WindowClosedException) {
            Log::info('waiting_reply.window_closed', ['conversation_id' => $c->id]);

            return false;
        }

        $c->forceFill(['bot_state' => array_merge($c->bot_state ?? [], [self::STATE_KEY => $at->toIso8601String()])])->save();

        return true;
    }

    /** Working-hours aware, and editable like every other script (null = switched off). */
    private function text(CarbonImmutable $at): ?string
    {
        $settings = BotSetting::current();

        if (! WorkingHours::configured($settings)) {
            return $this->prompter->script('waiting_ack_no_hours');
        }

        if (WorkingHours::isOpen($settings, $at)) {
            return $this->prompter->script('waiting_ack_in_hours');
        }

        $opening = WorkingHours::nextOpening($settings, $at);
        $text = $opening !== null ? $this->prompter->script('waiting_ack_after_hours') : $this->prompter->script('waiting_ack_no_hours');

        return $text !== null && $opening !== null ? str_replace('{next_opening}', WorkingHours::phrase($opening, $at), $text) : $text;
    }
}
