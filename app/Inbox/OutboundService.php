<?php

namespace App\Inbox;

use App\Analytics\ActivityLogger;
use App\Analytics\AttributionRecorder;
use App\Enums\ActorType;
use App\Enums\Handler;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageCreated;
use App\Events\MessageUpdated;
use App\Inbox\Jobs\SendOutboundMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\SafeBroadcast;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OutboundService
{
    public function __construct(
        private readonly WindowPolicy $windows,
        private readonly AttributionRecorder $attribution,
        private readonly ActivityLogger $logger,
        private readonly SoftLock $lock,
    ) {}

    /**
     * @param  array{tag?: string, template?: array{name: string, language: string, params: array}}  $options
     *
     * @throws AuthorizationException
     * @throws WindowClosedException
     */
    public function sendHuman(Conversation $c, User $u, string $body, array $options = []): Message
    {
        $this->authorize($c, $u);

        $options = $this->applyWindow($c, SenderType::User, $options);

        $message = DB::transaction(function () use ($c, $u, $body, $options) {
            $this->refreshLocked($c);

            $isFirstResponse = $c->first_response_at === null;
            $seconds = $isFirstResponse ? $this->firstResponseSeconds($c) : null;

            $message = $c->messages()->create([
                'platform' => $c->platform,
                'direction' => MessageDirection::Out,
                'sender_type' => SenderType::User,
                'user_id' => $u->id,
                'body' => $body,
                'status' => MessageStatus::Queued,
                'is_template' => ! empty($options['template']),
                // The moment the moderator pressed send (spec §11.3 outbound window start).
                // messages.created_at is only second precision; this plain bigint column isn't.
                'queued_at_ms' => $this->nowMs(),
            ]);
            $message->setRelation('user', $u);
            $message->setRelation('conversation', $c);

            if ($c->handler === Handler::Bot) {
                $c->handler = Handler::Human;
            }
            // A human reply always satisfies a pending "needs human" flag.
            $c->needs_human = false;
            $c->last_responder_id = $u->id;
            $c->first_responder_id ??= $u->id;
            if ($isFirstResponse) {
                $c->first_response_at = now();
            }
            $c->unread_count = 0;
            $c->last_message_at = now();
            $c->save();

            if ($isFirstResponse) {
                $this->logger->log(ActorType::User, $u, ActivityLogger::CONVERSATION_FIRST_RESPONSE, $message, $c, ['seconds' => $seconds]);
            }

            $this->attribution->recordOutbound($message);
            $this->lock->release($c, $u, broadcast: false);

            return $message;
        });

        // Hand off to the platform first: a broadcast failure must never strand a
        // committed message in "queued". The UI pushes are best-effort.
        SendOutboundMessage::dispatch($message->id, $options);

        SafeBroadcast::send(new MessageCreated($message));
        SafeBroadcast::send(new ConversationUpdated($c));

        return $message;
    }

    /**
     * @throws WindowClosedException
     */
    public function sendBot(Conversation $c, string $body): Message
    {
        $state = $this->windows->evaluate($c, SenderType::Bot);

        if (! $state->canSendText()) {
            throw new WindowClosedException($state->mode);
        }

        $message = DB::transaction(function () use ($c, $body) {
            $message = $c->messages()->create([
                'platform' => $c->platform,
                'direction' => MessageDirection::Out,
                'sender_type' => SenderType::Bot,
                'body' => $body,
                'status' => MessageStatus::Queued,
                'queued_at_ms' => $this->nowMs(),
            ]);
            $message->setRelation('conversation', $c);

            $c->forceFill(['last_message_at' => now()])->save();

            $this->attribution->recordOutbound($message);

            return $message;
        });

        SendOutboundMessage::dispatch($message->id);

        SafeBroadcast::send(new MessageCreated($message));
        SafeBroadcast::send(new ConversationUpdated($c));

        return $message;
    }

    /**
     * Internal system line shown in the thread; never sent to the platform.
     */
    public function sendSystem(Conversation $c, string $body): Message
    {
        $message = $c->messages()->create([
            'platform' => $c->platform,
            'direction' => MessageDirection::Out,
            'sender_type' => SenderType::System,
            'body' => $body,
            'status' => MessageStatus::Sent,
            'sent_at' => now(),
        ]);
        $message->setRelation('conversation', $c);

        SafeBroadcast::send(new MessageCreated($message));

        return $message;
    }

    /**
     * @throws AuthorizationException
     * @throws WindowClosedException
     */
    public function retry(Message $m, User $u): Message
    {
        $c = $m->conversation;

        $this->authorize($c, $u);

        if ($m->direction !== MessageDirection::Out
            || $m->sender_type === SenderType::System
            || $m->status !== MessageStatus::Failed) {
            throw new DomainException('Only failed outbound messages can be retried.');
        }

        // Template parameters are not persisted, so a retry is always sent as text.
        $options = $this->applyWindow($c, SenderType::User, []);

        // Retrying re-queues the send, so the outbound latency window starts again now.
        $m->forceFill(['status' => MessageStatus::Queued, 'error' => null, 'queued_at_ms' => $this->nowMs()])->save();

        SendOutboundMessage::dispatch($m->id, $options);

        SafeBroadcast::send(new MessageUpdated($m));

        return $m;
    }

    /**
     * Same expression the webhook controller uses for received_at_ms: epoch
     * milliseconds captured directly, never round-tripped through a
     * second-precision DATETIME(0) column.
     */
    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function authorize(Conversation $c, User $u): void
    {
        if (! $u->canAccessPlatform($c->platform)) {
            throw new AuthorizationException("You are not allowed to reply on {$c->platform->label()}.");
        }
    }

    /**
     * @throws WindowClosedException
     */
    private function applyWindow(Conversation $c, SenderType $sender, array $options): array
    {
        $state = $this->windows->evaluate($c, $sender);

        return match ($state->mode) {
            WindowState::OPEN => $options,
            WindowState::HUMAN_AGENT => array_merge($options, ['tag' => 'HUMAN_AGENT']),
            WindowState::TEMPLATE_ONLY => empty($options['template'])
                ? throw new WindowClosedException($state->mode)
                : $options,
            default => throw new WindowClosedException($state->mode),
        };
    }

    private function refreshLocked(Conversation $c): void
    {
        $fresh = Conversation::query()->whereKey($c->getKey())->lockForUpdate()->firstOrFail();
        $c->setRawAttributes($fresh->getAttributes(), true);
    }

    /**
     * Seconds from handover_at (when the bot handed over) or, otherwise, from the earliest
     * customer message after the last human/bot outbound (or the first customer message).
     */
    private function firstResponseSeconds(Conversation $c): ?int
    {
        $from = $c->handover_at;

        if ($from === null) {
            $lastOutboundId = $c->messages()
                ->where('direction', MessageDirection::Out->value)
                ->whereIn('sender_type', [SenderType::User->value, SenderType::Bot->value])
                ->max('id');

            // Spam/low-value customer messages (e.g. "شكرا 👍") never count as the
            // message a human is responding to (spec §11.1).
            $inbound = fn () => $c->messages()->where('direction', MessageDirection::In->value)
                ->where('is_spam', false)
                ->where('is_low_value', false)
                ->orderBy('id');

            $from = ($lastOutboundId ? $inbound()->where('id', '>', $lastOutboundId)->value('created_at') : null)
                ?? $inbound()->value('created_at');

            $from = $from ? Carbon::parse($from) : null;
        }

        return $from ? (int) max(0, round($from->diffInSeconds(now(), false))) : null;
    }
}
