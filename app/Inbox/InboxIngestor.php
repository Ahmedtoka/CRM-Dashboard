<?php

namespace App\Inbox;

use App\Analytics\ActivityLogger;
use App\Analytics\LatencyRecorder;
use App\Bot\Flow\ReplyScheduler;
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
use App\Inbox\Jobs\FetchCustomerProfile;
use App\Media\InboundAttachmentRecorder;
use App\Media\Jobs\DownloadInboundMedia;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Support\SafeBroadcast;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InboxIngestor
{
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
        private readonly InboundAttachmentRecorder $attachmentRecorder,
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
            [$message, $conversation, $pendingAttachments] = DB::transaction(function () use ($d, $account) {
                $identity = $this->resolver->resolve(
                    $d->platform,
                    $d->customerExternalId,
                    $d->customerName,
                    $d->customerUsername,
                    $d->customerAvatar,
                    $d->customerPhone,
                );

                $conversation = $this->openConversationFor($identity, $account);
                $opened = $conversation->wasRecentlyCreated;

                $message = $conversation->messages()->create([
                    'platform' => $d->platform,
                    'direction' => MessageDirection::In,
                    'sender_type' => SenderType::Customer,
                    'body' => $d->body,
                    'attachments' => $d->attachments ?: null,
                    'payload' => $d->payload,
                    'external_id' => $d->externalMessageId,
                    'status' => MessageStatus::Received,
                    'sent_at' => $d->occurredAt,
                ]);

                $pendingAttachments = $this->attachmentRecorder->record($message, $d->attachments);

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

                // Which ad or link she came through (2026-09-25): kept on the conversation, first touch wins.
                if ($d->referral !== null) {
                    app(AdAttribution::class)->apply($conversation, $d->referral, $opened);
                }

                return [$message, $conversation, $pendingAttachments];
            });
        } catch (UniqueConstraintViolationException) {
            return null; // concurrent duplicate of the same external message id
        }

        $message->setRelation('conversation', $conversation);
        $message->load('mediaAttachments');

        // Queue the bot before broadcasting so a realtime outage can't silence it.
        $this->maybeRunBot($message, $conversation);

        SafeBroadcast::send(new MessageCreated($message));
        SafeBroadcast::send(new ConversationUpdated($conversation));

        if ($event !== null) {
            $this->latency->inbound($event, $message);
        }

        // Downloads never run inside ingest (spec §1.3: inbound p95 target unchanged), and
        // dispatching happens last so a dispatch-time failure (e.g. a synchronous queue
        // connection immediately executing and failing the fetch) can never abort the rest
        // of ingestion above — it's reported and swallowed instead.
        //
        // The dispatch call must NOT be `return`ed out of the closure: dispatch() only
        // returns a PendingDispatch, whose __destruct() does the real work, so returning
        // it would let that destructor (and any exception it throws) run after rescue()'s
        // own try/catch has already exited.
        foreach ($pendingAttachments as $attachmentId) {
            rescue(function () use ($attachmentId) {
                DownloadInboundMedia::dispatch($attachmentId);
            }, report: true);
        }

        // Messenger/Instagram webhooks carry no sender name: fetch it (same dispatch rules as above).
        $identity = CustomerIdentity::where('platform', $d->platform)->where('external_id', $d->customerExternalId)->first();

        if ($identity !== null && $account !== null && FetchCustomerProfile::needed($identity)) {
            rescue(function () use ($identity, $account) {
                FetchCustomerProfile::dispatch($identity->id, $account->id);
            }, report: true);
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

            if ($d->error !== null && $d->error !== '') {
                $message->error = Str::limit($d->error, 250);
            }
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
        Customer::query()->whereKey($i->customer_id)->lockForUpdate()->first();

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

        // A `messaging_referrals` event on its own (she opened the thread from an ad, said nothing yet).
        if (trim((string) $message->body) === '' && empty($message->attachments) && str_starts_with((string) $message->external_id, 'referral:')) {
            return;
        }

        // Human-handled conversations are left alone: needs_human stays as it is
        // until a human replies (OutboundService clears it).
        if ($conversation->handler !== Handler::Bot || ! BotSetting::current()->enabled) {
            return;
        }

        rescue(function () use ($message, $conversation) {
            app(ReplyScheduler::class)->schedule($message, $conversation);
        }, report: true);
    }
}
