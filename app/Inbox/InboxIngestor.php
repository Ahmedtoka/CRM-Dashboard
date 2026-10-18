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
use App\Queue\Acknowledgement;
use App\Queue\QueueService;
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

        $account = $this->registry->connectedAccount($d->platform, $d->channelExternalId);

        try {
            [$message, $conversation, $pendingAttachments, $settled, $claim] = DB::transaction(function () use ($d, $account) {
                $identity = $this->resolver->resolve(
                    $d->platform,
                    $d->customerExternalId,
                    $d->customerName,
                    $d->customerUsername,
                    $d->customerAvatar,
                    $d->customerPhone,
                );

                // An answer a handler takes (an order-confirmation tap, 2026-10-16) goes into her latest
                // thread as it is: never reopened, not unread, no queue ticket, no bot (InboundInterceptors).
                $claim = $this->claimFor($d, $identity, $account);
                $conversation = $claim !== null ? $claim[1] : $this->openConversationFor($identity, $account, reopen: false);
                $claim = $claim[0] ?? null;
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

                // The platform window counts from when the customer wrote (never in the future,
                // never moving backwards for out-of-order webhooks).
                $occurred = $d->occurredAt->isFuture() ? CarbonImmutable::now() : $d->occurredAt;
                $previous = $conversation->last_customer_message_at;

                $conversation->forceFill([
                    'last_message_at' => now(),
                    'last_customer_message_at' => $previous && $previous->greaterThan($occurred) ? $previous : $occurred,
                ])->save();

                $identity->customer->forceFill(['last_contact_at' => now()])->save();

                // Handover queue: she came back inside an auto-close's return window (re-queued with
                // priority), or she wrote while queued / in a window (the silence clock restarts; a
                // thanks starts no reply clock). A rating answer (§3), or a thanks / emoji / sticker
                // after the close, is "settled" (spec 2026-09-30 §1): it stays in the thread but asks for nothing: not
                // unread, no queue hook (no ticket), no bot turn. The acknowledgement
                // is judged once here; the queue decides under the conversation lock, and the unread
                // count and the bot follow that one decision.
                $settled = $claim !== null || (! $message->is_spam
                    && app(QueueService::class)->settles($conversation, $message, app(Acknowledgement::class)->matches($message)));

                // Close cycle M-09: a settled message (a thanks, a rating answer) leaves a closed chat closed;
                // any other message (not a claimed confirmation answer) opens it, as before.
                if (! $settled) {
                    $conversation->increment('unread_count');
                }

                if (! $settled && $conversation->status !== ConversationStatus::Open) {
                    $reopened = $conversation->status === ConversationStatus::Resolved;
                    $conversation->forceFill(['status' => ConversationStatus::Open, 'resolved_at' => null, 'resolved_by_id' => null])->save();

                    if ($reopened) {
                        $this->logger->log(ActorType::System, null, ActivityLogger::CONVERSATION_REOPENED, $conversation, $conversation);
                    }
                }

                $this->logger->log(ActorType::System, null, ActivityLogger::MESSAGE_RECEIVED, $message, $conversation);

                // Which ad or link she came through (2026-09-25): kept on the conversation, first touch wins.
                if ($d->referral !== null) {
                    app(AdAttribution::class)->apply($conversation, $d->referral, $opened);
                }

                return [$message, $conversation, $pendingAttachments, $settled, $claim];
            });
        } catch (UniqueConstraintViolationException) {
            return null; // concurrent duplicate of the same external message id
        }

        $message->setRelation('conversation', $conversation);
        $message->load('mediaAttachments');

        $intercepted = $claim !== null && app(InboundInterceptors::class)->handle($claim, $message, $conversation);

        // A claimed answer the handler did not take after all (e.g. lost a race): an ordinary message.
        if ($claim !== null && ! $intercepted) {
            $settled = $this->settleUnclaimed($message, $conversation);
        }

        // Queue the bot before broadcasting so a realtime outage can't silence it. A settled
        // message (spec 2026-09-30: a rating answer, a thanks after the close) never reaches the bot.
        if (! $settled && ! $intercepted) {
            $this->maybeRunBot($message, $conversation);
        }

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
    public function openConversationFor(CustomerIdentity $i, ChannelAccount $a, ConversationSource $src = ConversationSource::Direct, ?int $sourceCommentId = null, bool $reopen = true): Conversation
    {
        Customer::query()->whereKey($i->customer_id)->lockForUpdate()->first();

        $query = fn () => Conversation::where('customer_id', $i->customer_id)
            ->where('channel_account_id', $a->id);

        $open = $query()->where('status', '!=', ConversationStatus::Resolved->value)->latest('id')->first();

        if ($open !== null) {
            return $open;
        }

        $resolved = $query()->where('status', ConversationStatus::Resolved->value)->latest('id')->first();

        // Close cycle M-09: for her inbound message (`reopen: false`) the ingest opens it once it knows the
        // message asks for something (a thanks or a rating answer leaves it resolved). Every other
        // caller (a comment's private reply, a confirmation, a test link, the load test) opens it here.
        if ($resolved !== null && ! $reopen) {
            return $resolved;
        }

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

    /**
     * The handler that takes this message, with her latest thread on the account (any status).
     * The customer row is locked first, as openConversationFor() does.
     *
     * @return array{0: \Closure, 1: Conversation}|null
     */
    private function claimFor(InboundMessageData $d, CustomerIdentity $identity, ChannelAccount $account): ?array
    {
        Customer::query()->whereKey($identity->customer_id)->lockForUpdate()->first();

        $latest = Conversation::query()->where('customer_id', $identity->customer_id)
            ->where('channel_account_id', $account->id)->latest('id')->first();
        $handle = $latest === null ? null : app(InboundInterceptors::class)->claim($d, $latest);

        return $handle === null ? null : [$handle, $latest];
    }

    /** What the ingest skipped for a claimed message, done afterwards: open, the queue hook, unread. */
    private function settleUnclaimed(Message $message, Conversation $conversation): bool
    {
        $conversation->forceFill(['status' => ConversationStatus::Open, 'resolved_at' => null])->save();

        $settled = ! $message->is_spam
            && app(QueueService::class)->settles($conversation, $message, app(Acknowledgement::class)->matches($message));

        if (! $settled) {
            $conversation->increment('unread_count');
        }

        return $settled;
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

        // Close cycle Q12: a person answered her from Business Suite a moment ago — the bot keeps quiet.
        if ($conversation->bot_paused_until !== null && $conversation->bot_paused_until->isFuture()) {
            return;
        }

        rescue(function () use ($message, $conversation) {
            app(ReplyScheduler::class)->schedule($message, $conversation);
        }, report: true);
    }
}
