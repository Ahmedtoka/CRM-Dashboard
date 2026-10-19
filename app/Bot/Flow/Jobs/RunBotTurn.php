<?php

namespace App\Bot\Flow\Jobs;

use App\Bot\BotEngine;
use App\Bot\Flow\ReplyScheduler;
use App\Bot\Flow\TurnSuperseded;
use App\Bot\Flows\WaitingReply;
use App\Bot\Jobs\BotHoldNotice;
use App\Bot\Router\TurnMetrics;
use App\Enums\Handler;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\BotRun;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class RunBotTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** An overlapped turn is released and retried (final fix wave I4), so tries are mostly lock waits. */
    public int $tries = 10;

    public int $maxExceptions = 3;

    public int $timeout = 75;

    public function __construct(public readonly int $conversationId)
    {
        $this->onQueue('bot');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("conv:{$this->conversationId}"))->releaseAfter(10)->expireAfter(120)];
    }

    public function handle(): void
    {
        $c = Conversation::find($this->conversationId);

        if ($c === null) {
            return;
        }

        // Handed to a person and still waiting: she gets one reassurance (owner, 2026-09-21)
        // instead of silence, and the bot stays out of the conversation otherwise.
        if ($c->handler !== Handler::Bot) {
            app(WaitingReply::class)->maybeSend($c);

            return;
        }

        // Close cycle Q12: a person answered her from outside the CRM a moment ago.
        if ($c->bot_paused_until !== null && $c->bot_paused_until->isFuture()) {
            return;
        }

        if ($c->bot_due_at && $c->bot_due_at->isFuture()) {
            return; // a newer message moved the due time; its own dispatch will run
        }

        $burst = app(ReplyScheduler::class)->burst($c);
        $lastId = $burst->last()?->id;
        $previousMarker = $c->bot_state['last_turn_message_id'] ?? null;
        $stateBefore = $c->bot_state ?? [];

        if ($lastId === null || $previousMarker === $lastId) {
            return; // nothing new, or this burst was already answered (retry / duplicate dispatch)
        }

        // Marked before handleTurn runs so a retry after a successful send never
        // sends twice. If handleTurn throws, the catch below undoes this marker
        // unless a reply already went out — otherwise a retry would see the
        // marker already matching and silently drop the customer's burst.
        $c->forceFill(['bot_state' => array_merge($c->bot_state ?? [], ['last_turn_message_id' => $lastId])])->save();

        $beforeMaxId = (int) $c->messages()->max('id');
        $this->startMetrics($c, $burst, $beforeMaxId);

        try {
            app(BotEngine::class)->handleTurn($c, $burst);
        } catch (TurnSuperseded $s) {
            // Round b1: nothing went out; her newer message's turn answers the whole burst at once.
            Cache::forget(BotHoldNotice::turnKey($c->id));
            // The state as it was before this turn (review I5: the planner memory, a pending offer…), and
            // a burst start that stays put even with no earlier marker (review C2: «ثانية واحدة» went out).
            $restored = $c->refresh()->handler === Handler::Bot ? $stateBefore : ($c->bot_state ?? []);
            $restored['last_turn_message_id'] = $previousMarker ?? ((int) $burst->first()->id - 1);
            $c->forceFill(['bot_state' => $restored])->save();
            $this->recordSuperseded($c, $burst, $s);
            $this->scheduleMessagesThatArrivedDuringTheTurn($c, $lastId);

            return;
        } catch (Throwable $e) {
            Cache::forget(BotHoldNotice::turnKey($c->id));

            $replied = Message::where('conversation_id', $c->id)
                ->where('id', '>', $beforeMaxId)
                ->whereIn('sender_type', [SenderType::Bot->value, SenderType::System->value])
                ->exists();

            if (! $replied) {
                $c->forceFill(['bot_state' => array_merge($c->bot_state ?? [], ['last_turn_message_id' => $previousMarker])])->save();
            }

            throw $e;
        }

        Cache::forget(BotHoldNotice::turnKey($c->id));
        $this->scheduleMessagesThatArrivedDuringTheTurn($c, $lastId);
    }

    /** Bot hybrid P0: the turn's clock (how long she waited for the burst) and its «ثانية واحدة» marker. */
    private function startMetrics(Conversation $c, Collection $burst, int $lastMessageId): void
    {
        $metrics = app(TurnMetrics::class);
        $metrics->reset();
        $metrics->startedAtMs = (int) floor(microtime(true) * 1000);
        $metrics->lastMessageId = $lastMessageId;
        $metrics->burstFirstId = $burst->first()?->id;
        $metrics->turn = (string) Str::uuid();
        $first = $burst->first()?->created_at;
        $metrics->burstWaitMs = $first !== null ? max(0, (int) ($metrics->startedAtMs - $first->getTimestampMs())) : null;

        Cache::put(BotHoldNotice::turnKey($c->id), $metrics->turn, now()->addMinutes(3));
    }

    /** The dropped answer's model cost stays counted (decision `superseded`, never a turn of its own). */
    private function recordSuperseded(Conversation $c, Collection $burst, TurnSuperseded $s): void
    {
        $metrics = app(TurnMetrics::class);

        rescue(fn () => BotRun::create([
            'conversation_id' => $c->id,
            'trigger_message' => $burst->pluck('body')->filter()->implode("\n"),
            'engine' => 'superseded',
            'model' => $s->model,
            'tier' => $s->tier,
            'decision' => 'superseded',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_usd' => round($s->costUsd, 6),
            'latency_ms' => $metrics->startedAtMs !== null ? (int) floor(microtime(true) * 1000) - $metrics->startedAtMs : 0,
        ]), report: true);
    }

    /** A customer message stored while this turn ran gets its own turn (final fix wave I4). */
    private function scheduleMessagesThatArrivedDuringTheTurn(Conversation $c, int $lastId): void
    {
        $c->refresh();

        if ($c->handler !== Handler::Bot) {
            return;
        }

        $newest = $c->messages()->where('direction', MessageDirection::In->value)->where('id', '>', $lastId)->orderByDesc('id')->first();

        if ($newest !== null) {
            app(ReplyScheduler::class)->schedule($newest, $c);
        }
    }
}
