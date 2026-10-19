<?php

namespace App\Bot\Flow;

use App\Bot\Jobs\BotHoldNotice;
use App\Bot\Router\TurnMetrics;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Cache;

/**
 * «One topic, several messages, several answers» (staff complaints, round b1, 2026-10-19): a turn
 * reads its burst when it starts, and the model then needs 2–5 s — longer than the 3 s burst wait.
 * A message she sends meanwhile used to get a turn of its own right after this one answered, so
 * she read two overlapping replies.
 *
 * Now a model turn that has sent nothing yet looks once more before it sends: when a newer
 * typed message of hers is already stored, the turn is superseded (TurnSuperseded) — RunBotTurn
 * puts the burst marker back, and the next turn answers ALL her messages in one reply. Never past
 * `crm.bot.supersede_max_seconds` from her first message (a customer who keeps typing still gets
 * an answer), never after a reply part went out («ثانية واحدة» excepted), and never for a button
 * tap (it is answered on its own).
 */
final class TurnSupersede
{
    public static function onceKey(int $conversationId, int $burstFirstId): string
    {
        return 'bot:superseded:'.$conversationId.':'.$burstFirstId;
    }

    /** Throws TurnSuperseded when a newer message of hers should be answered together with this burst. */
    public static function check(Conversation $c, float $costUsd = 0.0, ?string $tier = null, ?string $model = null): void
    {
        $metrics = app(TurnMetrics::class);
        $marker = ($c->bot_state ?? [])['last_turn_message_id'] ?? null;

        // Only inside a queued bot turn (not the eval, the preview or the sandbox).
        if ($metrics->turn === null || $metrics->startedAtMs === null || ! is_numeric($marker)) {
            return;
        }

        $newer = Message::query()->where('conversation_id', $c->id)->where('id', '>', (int) $marker)
            ->where('direction', MessageDirection::In->value)->where('sender_type', SenderType::Customer->value)
            ->orderBy('id')->get(['id', 'payload']);

        if ($newer->isEmpty() || $newer->contains(fn (Message $m) => filled($m->payload))) {
            return;
        }

        $firstAtMs = $metrics->startedAtMs - (int) ($metrics->burstWaitMs ?? 0);
        $max = max(0, (int) config('crm.bot.supersede_max_seconds', 20));

        if ((int) floor(microtime(true) * 1000) - $firstAtMs > $max * 1000) {
            return;
        }

        $hold = Cache::get(BotHoldNotice::sentKey($metrics->turn));
        $sent = Message::query()->where('conversation_id', $c->id)->where('id', '>', (int) $metrics->lastMessageId)
            ->whereIn('sender_type', [SenderType::Bot->value, SenderType::System->value, SenderType::User->value])
            ->when($hold, fn ($q) => $q->where('id', '!=', (int) $hold))
            ->exists();

        // At most once per burst (review M4): a customer who keeps typing costs one extra model call, not five.
        if (! $sent && Cache::add(self::onceKey($c->id, (int) ($metrics->burstFirstId ?? $marker)), 1, now()->addMinutes(5))) {
            throw new TurnSuperseded($costUsd, $tier, $model);
        }
    }
}
