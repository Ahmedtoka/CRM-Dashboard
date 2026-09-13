<?php

namespace App\Inbox;

use App\Analytics\ActivityLogger;
use App\Analytics\LatencyRecorder;
use App\Channels\ChannelRegistry;
use App\Channels\Data\DeliveryReceiptData;
use App\Channels\Data\InboundMessageData;
use App\Enums\ActorType;
use App\Enums\ConversationSource;
use App\Enums\ConversationStatus;
use App\Enums\Handler;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageCreated;
use App\Events\MessageUpdated;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\CustomerIdentity;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Support\SafeBroadcast;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class InboxIngestor
{
    private const BOT_JOB = 'App\\Bot\\Jobs\\RunBot';

    /** Outbound delivery progression; a receipt may only move a message forward. */
    private const RANK = [
        'queued' => 0,
        'sent' => 1,
        'delivered' => 2,
        'read' => 3,
    ];

    public function __construct(
        private readonly CustomerResolver $resolver,
        private readonly ActivityLogger $logger,
        private readonly ChannelRegistry $registry,
        private readonly ConversationPriorityClassifier $priorityClassifier,
        private readonly LatencyRecorder $latency,
    ) {}

    /**
     * @param  WebhookEvent|null  $event  the webhook event this message came from, when known —
     *                                    used to record inbound latency (spec §11.3)
     * @return Message|null null when the external message id was already ingested
     */
    public function ingestMessage(InboundMessageData $d, ?WebhookEvent $event = null): ?Message
    {
        if ($this->isDuplicate($d)) {
            return null;
        }

        $account = ChannelAccount::where('platform', $d->platform)
            ->where('external_id', $d->channelExternalId)
            ->first() ?? $this->registry->account($d->platform);

        try {
            [$message, $conversation] = DB::transaction(function () use ($d, $account) {
                $identity = $this->resolver->resolve(
                    $d->platform,
                    $d->customerExternalId,
                    $d->customerName,
                    $d->customerUsername,
                    $d->customerAvatar,
                    $d->customerPhone,
                );

                $conversation = $this->openConversationFor($identity, $account);

                $message = $conversation->messages()->create([
                    'platform' => $d->platform,
                    'direction' => MessageDirection::In,
                    'sender_type' => SenderType::Customer,
                    'body' => $d->body,
                    'attachments' => $d->attachments ?: null,
                    'external_id' => $d->externalMessageId,
                    'status' => MessageStatus::Received,
                    'sent_at' => $d->occurredAt,
                ]);

                $verdict = $this->priorityClassifier->classify($message, $identity);
                $this->priorityClassifier->apply($message, $conversation, $verdict);

                $conversation->increment('unread_count');

                // The platform window counts from when the customer wrote (never in the future,
                // never moving backwards for out-of-order webhooks).
                $occurred = $d->occurredAt->isFuture() ? CarbonImmutable::now() : $d->occurredAt;
                $previous = $conversation->last_customer_message_at;

                $conversation->forceFill([
                    'status' => ConversationStatus::Open,
                    'last_message_at' => now(),
                    'last_customer_message_at' => $previous && $previous->greaterThan($occurred) ? $previous : $occurred,
                ])->save();

                $identity->customer->forceFill(['last_contact_at' => now()])->save();

                $this->logger->log(ActorType::System, null, ActivityLogger::MESSAGE_RECEIVED, $message, $conversation);

                return [$message, $conversation];
            });
        } catch (UniqueConstraintViolationException) {
            return null; // concurrent duplicate of the same external message id
        }

        $message->setRelation('conversation', $conversation);

        // Queue the bot before broadcasting so a realtime outage can't silence it.
        $this->maybeRunBot($message, $conversation);

        SafeBroadcast::send(new MessageCreated($message));
        SafeBroadcast::send(new ConversationUpdated($conversation));

        if ($event !== null) {
            $this->latency->inbound($event, $message);
        }

        return $message;
    }

    public function ingestReceipt(DeliveryReceiptData $d): void
    {
        $message = Message::where('platform', $d->platform)
            ->where('external_id', $d->externalMessageId)
            ->first();

        // Ruling: Messenger/Instagram read watermarks carry no message id, so they match
        // nothing here; conversation-level read tracking is not part of this build.
        if ($message === null || $message->direction !== MessageDirection::Out) {
            return;
        }

        if ($d->status === MessageStatus::Failed) {
            if (! in_array($message->status, [MessageStatus::Queued, MessageStatus::Sent], true)) {
                return;
            }
            $message->status = MessageStatus::Failed;
        } else {
            $new = self::RANK[$d->status->value] ?? null;
            $current = self::RANK[$message->status->value] ?? null;

            if ($new === null || $current === null || $new <= $current) {
                return;
            }

            $message->status = $d->status;
            $message->sent_at ??= $d->occurredAt;

            if ($new >= self::RANK['delivered']) {
                $message->delivered_at ??= $d->occurredAt;
            }
            if ($new >= self::RANK['read']) {
                $message->read_at ??= $d->occurredAt;
            }
        }

        $message->save();

        SafeBroadcast::send(new MessageUpdated($message));
    }

    /**
     * Callers run this inside a transaction. The customer row is locked first so two
     * webhooks for the same customer (e.g. a message and a comment private reply, or
     * Meta retries) serialize here instead of both creating a new conversation.
     */
    public function openConversationFor(CustomerIdentity $i, ChannelAccount $a, ConversationSource $src = ConversationSource::Direct, ?int $sourceCommentId = null): Conversation
    {
        \App\Models\Customer::query()->whereKey($i->customer_id)->lockForUpdate()->first();

        $query = fn () => Conversation::where('customer_id', $i->customer_id)
            ->where('channel_account_id', $a->id);

        $open = $query()->where('status', '!=', ConversationStatus::Resolved->value)->latest('id')->first();

        if ($open !== null) {
            return $open;
        }

        $resolved = $query()->where('status', ConversationStatus::Resolved->value)->latest('id')->first();

        if ($resolved !== null) {
            $resolved->forceFill([
                'status' => ConversationStatus::Open,
                'resolved_at' => null,
                'resolved_by_id' => null,
            ])->save();

            $this->logger->log(ActorType::System, null, ActivityLogger::CONVERSATION_REOPENED, $resolved, $resolved);

            return $resolved;
        }

        return Conversation::create([
            'customer_id' => $i->customer_id,
            'channel_account_id' => $a->id,
            'platform' => $a->platform,
            'status' => ConversationStatus::Open,
            'handler' => Handler::Bot,
            'needs_human' => false,
            'source' => $src,
            'source_comment_id' => $sourceCommentId,
            'unread_count' => 0,
        ]);
    }

    private function isDuplicate(InboundMessageData $d): bool
    {
        return Message::where('platform', $d->platform)
            ->where('external_id', $d->externalMessageId)
            ->exists();
    }

    private function maybeRunBot(Message $message, Conversation $conversation): void
    {
        // Spam never reaches the bot and never flips needs_human (spec §11.1).
        if ($message->is_spam) {
            return;
        }

        // Human-handled conversations are left alone: needs_human stays as it is
        // until a human replies (OutboundService clears it).
        if ($conversation->handler !== Handler::Bot || ! BotSetting::current()->enabled) {
            return;
        }

        $job = self::BOT_JOB;

        if (! class_exists($job)) {
            return; // Bot engine (Task 4) not installed.
        }

        dispatch(new $job($message->id))->onQueue('bot');
    }
}
