<?php

namespace App\Inbox;

use App\Analytics\ActivityLogger;
use App\Analytics\AttributionRecorder;
use App\Enums\ActorType;
use App\Enums\AttachmentStatus;
use App\Enums\Handler;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageCreated;
use App\Events\MessageUpdated;
use App\Inbox\Jobs\SendOutboundMessage;
use App\Media\MediaPolicy;
use App\Media\MediaRejected;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Support\SafeBroadcast;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class OutboundService
{
    public function __construct(
        private readonly WindowPolicy $windows,
        private readonly AttributionRecorder $attribution,
        private readonly ActivityLogger $logger,
        private readonly SoftLock $lock,
        private readonly MediaPolicy $media,
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

        return $this->dispatchHuman($c, $u, [['body' => $body, 'attachment' => null]], $options)->first();
    }

    /**
     * Uploads first (spec §1.4): each attachment id must already be a stored,
     * unclaimed upload owned by this user. One outbound `messages` row per
     * attachment, the caption (if any) riding on the first.
     *
     * @param  list<int>  $attachmentIds
     * @param  array{tag?: string}  $options
     * @return Collection<int, Message>
     *
     * @throws AuthorizationException
     * @throws WindowClosedException
     * @throws MediaRejected
     */
    public function sendHumanWithAttachments(Conversation $c, User $u, ?string $caption, array $attachmentIds, array $options = []): Collection
    {
        $this->authorize($c, $u);

        // Attachments never ride on the TEMPLATE_ONLY exception a text send gets
        // when a template is present: a closed window rejects media outright, the
        // same as a free-form text send would. Checked directly (canSendText())
        // rather than through applyWindow()'s template branch, since a stray
        // options['template'] must never let free-form media slip through a
        // template-only window (sendMessage's validation also refuses to combine
        // attachment_ids with template, but this guard does not rely on that alone).
        $window = $this->windows->evaluate($c, SenderType::User);

        if (! $window->canSendText()) {
            throw new WindowClosedException($window->mode);
        }

        $ids = array_values(array_unique(array_map('intval', $attachmentIds)));
        $found = MessageAttachment::query()->whereIn('id', $ids)->where('uploaded_by', $u->id)
            ->whereNull('message_id')->where('status', AttachmentStatus::Stored->value)->get()->keyBy('id');

        if ($ids === [] || $found->count() !== count($ids)) {
            throw new MediaRejected(MediaPolicy::NOT_CLAIMABLE);
        }

        $rows = [];

        foreach ($ids as $i => $id) {
            $this->media->assertSendable($found[$id], $c->platform);
            $rows[] = ['body' => $i === 0 && $caption !== null && trim($caption) !== '' ? $caption : null, 'attachment' => $found[$id]];
        }

        return $this->dispatchHuman($c, $u, $rows, $options);
    }

    /**
     * @param  list<array{body: ?string, attachment: ?MessageAttachment}>  $rows
     * @param  array{tag?: string, template?: array{name: string, language: string, params: array}}  $options
     * @return Collection<int, Message>
     *
     * @throws AuthorizationException
     * @throws WindowClosedException
     */
    private function dispatchHuman(Conversation $c, User $u, array $rows, array $options): Collection
    {
        // Both public entry points (sendHuman(), sendHumanWithAttachments()) already
        // authorize before calling here — not repeated to avoid a redundant check.
        $options = $this->applyWindow($c, SenderType::User, $options);

        $messages = DB::transaction(function () use ($c, $u, $rows, $options) {
            $this->refreshLocked($c);

            $isFirstResponse = $c->first_response_at === null;
            $seconds = $isFirstResponse ? $this->firstResponseSeconds($c) : null;
            $created = collect();

            foreach ($rows as $row) {
                $message = $c->messages()->create([
                    'platform' => $c->platform,
                    'direction' => MessageDirection::Out,
                    'sender_type' => SenderType::User,
                    'user_id' => $u->id,
                    'body' => $row['body'],
                    'status' => MessageStatus::Queued,
                    'is_template' => ! empty($options['template']),
                    // The moment the moderator pressed send (spec §11.3 outbound window start).
                    // messages.created_at is only second precision; this plain bigint column isn't.
                    'queued_at_ms' => $this->nowMs(),
                ]);

                if ($row['attachment'] !== null) {
                    // Link BEFORE anything else touches this attachment: the send job
                    // mints a temporary public URL for Meta off this message_id, and
                    // publicShow() refuses to serve an orphan (message_id null).
                    $linked = MessageAttachment::query()->whereKey($row['attachment']->id)->whereNull('message_id')->update(['message_id' => $message->id]);

                    if ($linked !== 1) {
                        throw new MediaRejected(MediaPolicy::NOT_CLAIMABLE);
                    }

                    $row['attachment']->message_id = $message->id;
                }

                $message->setRelation('mediaAttachments', collect($row['attachment'] ? [$row['attachment']] : []));
                $message->setRelation('user', $u);
                $message->setRelation('conversation', $c);
                $this->attribution->recordOutbound($message);
                $created->push($message);
            }

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
                $this->logger->log(ActorType::User, $u, ActivityLogger::CONVERSATION_FIRST_RESPONSE, $created->first(), $c, ['seconds' => $seconds]);
            }

            $this->lock->release($c, $u, broadcast: false);

            return $created;
        });

        // Hand off to the platform first: a broadcast failure must never strand a
        // committed message in "queued". The UI pushes are best-effort.
        // Several attachments go out as one ordered chain (final fix wave M2) so the
        // customer receives them in the order picked, caption first. A link that
        // fails outright hands the rest of the chain back as independent jobs from
        // SendOutboundMessage::failed(), so nothing is silently dropped.
        if ($messages->count() > 1) {
            Bus::chain($messages->map(fn (Message $m) => new SendOutboundMessage($m->id, $options))->all())
                ->onQueue('outbound')
                ->dispatch();
        } else {
            SendOutboundMessage::dispatch($messages->first()->id, $options);
        }

        foreach ($messages as $message) {
            SafeBroadcast::send(new MessageCreated($message));
        }
        SafeBroadcast::send(new ConversationUpdated($c));

        return $messages;
    }

    /**
     * @param  int  $delayMs  queue the platform send this much later (a later part of a paced reply)
     * @param  bool  $skipIfHumanTookOver  at send time, drop it when the conversation is no longer the bot's
     * @param  list<array{title: string, payload: string}>  $buttons  quick-reply buttons offered with this message
     *
     * @throws WindowClosedException
     */
    public function sendBot(Conversation $c, string $body, int $delayMs = 0, bool $skipIfHumanTookOver = false, array $buttons = []): Message
    {
        $state = $this->windows->evaluate($c, SenderType::Bot);

        if (! $state->canSendText()) {
            throw new WindowClosedException($state->mode);
        }

        $message = DB::transaction(function () use ($c, $body, $buttons) {
            $message = $c->messages()->create([
                'platform' => $c->platform,
                'direction' => MessageDirection::Out,
                'sender_type' => SenderType::Bot,
                'body' => $body,
                'buttons' => $buttons !== [] ? array_values($buttons) : null,
                'status' => MessageStatus::Queued,
                'queued_at_ms' => $this->nowMs(),
            ]);
            $message->setRelation('conversation', $c);

            $c->forceFill(['last_message_at' => now()])->save();

            $this->attribution->recordOutbound($message);

            return $message;
        });

        $send = new SendOutboundMessage($message->id, [], $skipIfHumanTookOver);

        dispatch($delayMs > 0 ? $send->delay(now()->addMilliseconds($delayMs)) : $send);

        SafeBroadcast::send(new MessageCreated($message));
        SafeBroadcast::send(new ConversationUpdated($c));

        return $message;
    }

    /**
     * A bot-sent attachment (e.g. the size chart image, spec §4.2). Same window
     * rule as a bot text: free-form window only — bots never use the
     * HUMAN_AGENT tag, and no options are passed to the send job.
     *
     * @throws WindowClosedException
     * @throws MediaRejected
     */
    public function sendBotAttachment(Conversation $c, MessageAttachment $attachment, ?string $caption = null): Message
    {
        $state = $this->windows->evaluate($c, SenderType::Bot);

        if (! $state->canSendText()) {
            throw new WindowClosedException($state->mode);
        }

        $this->media->assertSendable($attachment, $c->platform);

        $message = DB::transaction(function () use ($c, $attachment, $caption) {
            $message = $c->messages()->create([
                'platform' => $c->platform,
                'direction' => MessageDirection::Out,
                'sender_type' => SenderType::Bot,
                'body' => $caption !== null && trim($caption) !== '' ? $caption : null,
                'status' => MessageStatus::Queued,
                'queued_at_ms' => $this->nowMs(),
            ]);

            $linked = MessageAttachment::query()->whereKey($attachment->id)->whereNull('message_id')->update(['message_id' => $message->id]);

            if ($linked !== 1) {
                throw new MediaRejected(MediaPolicy::NOT_CLAIMABLE);
            }

            $attachment->message_id = $message->id;
            $message->setRelation('mediaAttachments', collect([$attachment]));
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
