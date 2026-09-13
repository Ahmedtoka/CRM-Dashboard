<?php

namespace App\Analytics;

use App\Enums\ActorType;
use App\Enums\MessageDirection;
use App\Enums\ParticipantRole;
use App\Enums\SenderType;
use App\Models\ActivityLog;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\Order;
use Carbon\CarbonInterface;

/**
 * Participant roles per spec §5.3: first / continued / follow_up.
 */
class AttributionRecorder
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function recordOutbound(Message $m): void
    {
        if ($m->direction !== MessageDirection::Out) {
            return;
        }

        $isBot = $m->sender_type === SenderType::Bot;

        if (! $isBot && ($m->sender_type !== SenderType::User || $m->user_id === null)) {
            return;
        }

        $conversation = $m->conversation;
        $at = $m->created_at ?? now();

        $row = $isBot
            ? $this->botRow($conversation)
            : $this->humanRow($conversation, (int) $m->user_id, $at, $this->previousOutbound($conversation, $m->id));

        $row->messages_count = ($row->messages_count ?? 0) + 1;
        $row->first_message_at ??= $at;
        $row->last_message_at = $at;
        $row->save();

        $this->logger->log(
            $isBot ? ActorType::Bot : ActorType::User,
            $isBot ? null : $m->user,
            ActivityLogger::MESSAGE_SENT,
            $m,
            $conversation,
            ['role' => $row->role->value],
        );
    }

    /**
     * An order is not a message: it never creates or changes participant rows.
     * It only records `order.created`; its effect on roles is the follow_up rule in isFollowUp().
     */
    public function recordOrder(Order $o): void
    {
        $creator = $o->createdBy;

        $this->logger->log(
            $creator ? ActorType::User : ActorType::System,
            $creator,
            ActivityLogger::ORDER_CREATED,
            $o,
            $o->conversation,
            ['type' => $o->type?->value, 'total' => $o->total],
        );
    }

    private function humanRow(Conversation $c, int $userId, CarbonInterface $at, ?Message $prev): ConversationParticipant
    {
        // The very first human outbound message is always `first`, even if an order already exists.
        $hasHumanFirst = ConversationParticipant::where('conversation_id', $c->id)
            ->whereNotNull('user_id')
            ->where('role', ParticipantRole::First->value)
            ->exists();

        if (! $hasHumanFirst) {
            return ConversationParticipant::firstOrNew([
                'conversation_id' => $c->id,
                'user_id' => $userId,
                'role' => ParticipantRole::First->value,
            ]);
        }

        $latest = $this->latestRow($c, $userId);

        if ($this->isFollowUp($c, $at, $prev, $latest)) {
            return ConversationParticipant::firstOrNew([
                'conversation_id' => $c->id,
                'user_id' => $userId,
                'role' => ParticipantRole::FollowUp->value,
            ]);
        }

        return $latest ?? new ConversationParticipant([
            'conversation_id' => $c->id,
            'user_id' => $userId,
            'role' => ParticipantRole::Continued,
        ]);
    }

    /**
     * Bot participant (user_id = null): first/continued, never follow_up.
     */
    private function botRow(Conversation $c): ConversationParticipant
    {
        $existing = $this->latestRow($c, null);

        if ($existing !== null) {
            return $existing;
        }

        $anyFirst = ConversationParticipant::where('conversation_id', $c->id)
            ->where('role', ParticipantRole::First->value)
            ->exists();

        return new ConversationParticipant([
            'conversation_id' => $c->id,
            'user_id' => null,
            'role' => $anyFirst ? ParticipantRole::Continued : ParticipantRole::First,
        ]);
    }

    private function isFollowUp(Conversation $c, CarbonInterface $at, ?Message $prev, ?ConversationParticipant $latest): bool
    {
        $threshold = (int) config('crm.attribution.follow_up_hours', 12) * 3600;

        if ($prev !== null && abs($prev->created_at->diffInSeconds($at, false)) >= $threshold) {
            return true;
        }

        // "An order already exists" only counts once a human `first` row exists.
        $hasHumanFirst = ConversationParticipant::where('conversation_id', $c->id)
            ->whereNotNull('user_id')
            ->where('role', ParticipantRole::First->value)
            ->exists();

        $hasOrder = $hasHumanFirst && $c->orders()
            ->where('created_at', '<=', $at)
            ->exists();

        if ($hasOrder) {
            return true;
        }

        $since = $latest?->updated_at ?? $prev?->created_at;

        if ($since === null) {
            return false;
        }

        return ActivityLog::where('conversation_id', $c->id)
            ->where('action', ActivityLogger::CONVERSATION_REOPENED)
            ->where('created_at', '>', $since)
            ->where('created_at', '<=', $at)
            ->exists();
    }

    private function latestRow(Conversation $c, ?int $userId): ?ConversationParticipant
    {
        return ConversationParticipant::where('conversation_id', $c->id)
            ->when($userId === null, fn ($q) => $q->whereNull('user_id'), fn ($q) => $q->where('user_id', $userId))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->first();
    }

    private function previousOutbound(Conversation $c, ?int $beforeId, ?CarbonInterface $atOrBefore = null): ?Message
    {
        return Message::where('conversation_id', $c->id)
            ->where('direction', MessageDirection::Out->value)
            ->whereIn('sender_type', [SenderType::User->value, SenderType::Bot->value])
            ->when($beforeId !== null, fn ($q) => $q->where('id', '<', $beforeId))
            ->when($atOrBefore !== null, fn ($q) => $q->where('created_at', '<=', $atOrBefore))
            ->orderByDesc('id')
            ->first();
    }
}
