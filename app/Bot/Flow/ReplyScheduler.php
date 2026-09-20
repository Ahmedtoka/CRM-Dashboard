<?php

namespace App\Bot\Flow;

use App\Bot\Flow\Jobs\RunBotTurn;
use App\Bot\Flows\FlowState;
use App\Bot\Flows\Steps\PhotoStep;
use App\Channels\ChannelRegistry;
use App\Enums\MessageDirection;
use App\Models\BotFlow;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\CustomerIdentity;
use App\Models\Message;
use Illuminate\Support\Collection;

class ReplyScheduler
{
    /** A second photo sent right after the first still lands in the same burst. */
    private const PHOTO_STEP_WAIT_SECONDS = 2;

    public function __construct(private readonly BurstPolicy $policy) {}

    public function schedule(Message $inbound, Conversation $c): void
    {
        $s = BotSetting::current();
        $burst = $this->burst($c);
        $base = (int) $s->burst_wait_seconds;
        $max = (int) $s->burst_max_wait_seconds;
        // A button tap (quick-reply/postback payload, or a typed number/title matched
        // to the last offered buttons) answers immediately: skipping the burst wait
        // avoids making the customer wait after they've already made a menu choice.
        $isButtonTap = filled($inbound->payload);
        // A guided flow waiting on a photo answers right away when the customer sends
        // one, instead of sitting through the burst's max wait as if they were "still
        // typing" — the short wait below still lets a second photo join the same burst.
        $isAwaitedPhoto = ! $isButtonTap && $this->isAwaitingPhoto($c) && PhotoStep::hasImage($inbound);
        $wait = $isButtonTap ? 0 : ($isAwaitedPhoto ? self::PHOTO_STEP_WAIT_SECONDS : $this->policy->waitSeconds(
            $burst->pluck('body')->map(fn ($b) => (string) $b)->all(),
            $inbound->mediaAttachments()->exists() && trim((string) $inbound->body) === '',
            $base,
            $max,
        ));

        // Final fix wave I8: a customer who keeps typing never pushes the answer past
        // the burst's first message + the max wait.
        $due = now()->addSeconds($wait);
        $first = $burst->first();

        if ($first?->created_at !== null) {
            $due = $due->min($first->created_at->copy()->addSeconds(max($base, $max)));
        }

        $c->forceFill(['bot_due_at' => $due])->save();

        // Typing starts once, when the burst's first message schedules; later messages keep it.
        if (($wait > 0 || $isButtonTap) && ($first === null || $first->id === $inbound->id)) {
            rescue(fn () => $this->typing($c, true), report: false);
        }

        RunBotTurn::dispatch($c->id)->delay($due->isFuture() ? $due : null);
    }

    /**
     * Customer messages not answered yet, oldest first (final fix wave I4): those after
     * bot_state.last_turn_message_id — so a message stored while a turn was sending is never
     * hidden behind the bot's reply — or, before any turn ran, after the last bot/user outbound.
     * A human reply always closes a burst.
     */
    public function burst(Conversation $c): Collection
    {
        $marker = ($c->bot_state ?? [])['last_turn_message_id'] ?? null;
        $out = fn (array $senders) => $c->messages()->where('direction', MessageDirection::Out->value)->whereIn('sender_type', $senders)->max('id');
        $after = $marker ?? $out(['bot', 'user']);
        $lastHuman = $out(['user']);

        return $c->messages()->where('direction', MessageDirection::In->value)
            ->when($after, fn ($q) => $q->where('id', '>', $after))
            ->when($lastHuman, fn ($q) => $q->where('id', '>', $lastHuman))
            ->orderBy('id')->get();
    }

    /** Whether the conversation's active guided flow is currently sitting on a `photo` step. */
    private function isAwaitingPhoto(Conversation $c): bool
    {
        $flow = FlowState::flow($c);

        if ($flow === null) {
            return false;
        }

        $step = BotFlow::active($flow['key'])?->definition['steps'][$flow['step']] ?? null;

        return is_array($step) && ($step['type'] ?? null) === 'photo';
    }

    public function typing(Conversation $c, bool $on): void
    {
        $identity = CustomerIdentity::where('customer_id', $c->customer_id)->where('platform', $c->platform)->latest('id')->first();
        $account = $c->channelAccount;

        if ($identity && $account) {
            app(ChannelRegistry::class)->adapterFor($account)->typing($account, $identity, $on);
        }
    }
}
