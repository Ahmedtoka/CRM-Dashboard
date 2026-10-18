<?php

namespace App\Bot\Jobs;

use App\Bot\Flow\ReplyScheduler;
use App\Bot\Flows\FlowPrompter;
use App\Bot\Router\TurnMetrics;
use App\Enums\Handler;
use App\Enums\SenderType;
use App\Inbox\OutboundService;
use App\Inbox\WindowClosedException;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * «ثانية واحدة يا فندم» (owner B2, 2026-10-17): a turn that needs a model and has not answered
 * within `crm.bot.hold_notice.after_seconds` tells her it is coming. Queued with that delay on
 * its own queue (never `bot`, whose workers are busy with the very turn it waits for).
 *
 * It sends nothing when the turn already answered (a bot, system or staff message after the
 * turn started), when the turn is over (its marker is gone), when it is early (the sync queue in
 * tests runs it at once), when a person took the chat, or when she already got one in the last
 * `crm.bot.hold_notice.cooldown_minutes`. Armed once per turn, and only on the model paths.
 */
class BotHoldNotice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $conversationId,
        public readonly string $turn,
        public readonly int $startedAtMs,
        public readonly int $lastMessageId,
    ) {
        $this->onQueue((string) config('crm.bot.hold_notice.queue', 'outbound'));
    }

    /** Arms the notice for the running turn: at most once per turn, never when switched off. */
    public static function arm(Conversation $c): void
    {
        $metrics = app(TurnMetrics::class);
        $after = (float) config('crm.bot.hold_notice.after_seconds', 2);

        // A sync queue would run it before the reply is even composed: it can only ever be wrong there.
        if (! config('crm.bot.hold_notice.enabled', true) || $metrics->holdArmed || $metrics->turn === null || $after <= 0
            || Queue::connection() instanceof SyncQueue) {
            return;
        }

        $metrics->holdArmed = true;

        rescue(fn () => self::dispatch($c->id, $metrics->turn, (int) $metrics->startedAtMs, (int) $metrics->lastMessageId)
            ->delay(now()->addMilliseconds((int) ($after * 1000))), report: false);
    }

    public static function turnKey(int $conversationId): string
    {
        return 'bot:turn:'.$conversationId;
    }

    /** The «ثانية واحدة» message a turn got (round b1: it does not count as the turn's answer). */
    public static function sentKey(string $turn): string
    {
        return 'bot:hold-msg:'.$turn;
    }

    /** A bot, system or staff message went out after the turn started. */
    private function answered(Conversation $c): bool
    {
        return Message::where('conversation_id', $c->id)->where('id', '>', $this->lastMessageId)
            ->whereIn('sender_type', [SenderType::Bot->value, SenderType::System->value, SenderType::User->value])->exists();
    }

    public function handle(): void
    {
        $after = (int) (1000 * (float) config('crm.bot.hold_notice.after_seconds', 2));

        if (Cache::get(self::turnKey($this->conversationId)) !== $this->turn || (int) floor(microtime(true) * 1000) - $this->startedAtMs < $after) {
            return;
        }

        $c = Conversation::find($this->conversationId);

        if ($c === null || $c->handler !== Handler::Bot) {
            return;
        }

        if ($this->answered($c) || ! Cache::add('bot:hold-sent:'.$c->id, 1, now()->addMinutes((int) config('crm.bot.hold_notice.cooldown_minutes', 10)))) {
            return;
        }

        $text = app(FlowPrompter::class)->script('hold_short') ?? 'ثانية واحدة يا فندم';

        // Once more right before sending (review #17): the reply may have gone out meanwhile.
        if (Cache::get(self::turnKey($this->conversationId)) !== $this->turn || $this->answered($c)) {
            return;
        }

        try {
            $sent = app(OutboundService::class)->sendBot($c, $text);
            Cache::put(self::sentKey($this->turn), $sent->id, now()->addMinutes(3));
            rescue(fn () => app(ReplyScheduler::class)->typing($c, true), report: false);
        } catch (WindowClosedException) {
            Log::info('bot.hold_notice_window_closed', ['conversation_id' => $c->id]);
        }
    }
}
