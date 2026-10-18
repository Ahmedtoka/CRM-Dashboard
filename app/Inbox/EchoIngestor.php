<?php

namespace App\Inbox;

use App\Channels\ChannelRegistry;
use App\Channels\Data\OutboundEchoData;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageCreated;
use App\Events\MessageUpdated;
use App\Inbox\Jobs\SendOutboundMessage;
use App\Media\InboundAttachmentRecorder;
use App\Media\Jobs\DownloadInboundMedia;
use App\Models\Conversation;
use App\Models\CustomerIdentity;
use App\Models\Message;
use App\Queue\EchoPolicy;
use App\Queue\WindowLifecycle;
use App\Support\SafeBroadcast;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Replies sent outside the CRM (2026-10-17). Meta mirrors every message the page sends back
 * on the webhook as an echo. The CRM's own sends are already in the thread and are skipped:
 *
 *  - by app id: a Messenger echo names the app that sent it, and ours is config('crm.meta.app_id');
 *  - by mid: the send job stored the message id Meta returned (Instagram echoes carry no app id);
 *  - by a twin: the echo can overtake the send job saving that mid, and a caption or a card's
 *    text fallback goes out as a second message whose mid is never stored, so an echo matching
 *    one of our recent sends in the thread (crmTwin()) is skipped too. If an echo still slips
 *    in first, the send job's save drops it (SendOutboundMessage::recordSent()), so a message
 *    is never shown twice.
 *
 * Anything left was sent by a person from Meta Business Suite or the phone app: recorded as an
 * outbound staff message (sender_type user, no user) flagged `sent_outside_crm`. It answers the
 * customer for the team's metrics (waiting-now, team response time: MetricsService counts any
 * user/bot outbound) but belongs to no agent (per-agent stats key on user_id), and it never
 * reopens the thread, marks it unread, moves the customer's 24h window, hands over or runs the bot.
 *
 * WhatsApp Cloud API has no echoes, so a reply from the WhatsApp Business app cannot appear here.
 */
class EchoIngestor
{
    /** How long one of our sends may sit queued and still be taken for the echo's twin. */
    private const TWIN_MINUTES = 10;

    /** Attachment kinds InboundAttachmentRecorder stores (a `template` or `fallback` is not one). */
    private const MEDIA_TYPES = ['image', 'audio', 'voice', 'video', 'file', 'document', 'sticker'];

    public function __construct(
        private readonly ChannelRegistry $registry,
        private readonly InboundAttachmentRecorder $attachmentRecorder,
    ) {}

    /** @return Message|null null when it is one of ours, already recorded, or for no known thread */
    public function ingest(OutboundEchoData $d): ?Message
    {
        $ourApp = (string) config('crm.meta.app_id');

        if ($d->appId !== null && $ourApp !== '' && $d->appId === $ourApp) {
            return null;
        }

        if (Message::where('platform', $d->platform)->where('external_id', $d->externalMessageId)->exists()) {
            return null;
        }

        $account = $this->registry->connectedAccount($d->platform, $d->channelExternalId);

        $identity = CustomerIdentity::where('platform', $d->platform)->where('external_id', $d->customerExternalId)->first();

        $conversation = $identity === null ? null : Conversation::query()
            ->where('customer_id', $identity->customer_id)
            ->where('channel_account_id', $account->id)
            ->latest('id')
            ->first();

        // Staff can only write first inside a window the customer opened, so a thread exists;
        // an echo without one (a customer we never stored) is left out rather than invented.
        if ($conversation === null) {
            return null;
        }

        $twin = $this->crmTwin($conversation, $d);

        if ($twin !== null) {
            // One of ours the send job gave up on, though Meta did deliver it (a timeout): the echo
            // proves it went out, so it is marked sent instead of shown twice (failed + outside).
            if ($twin->status === MessageStatus::Failed && $twin->external_id === null) {
                rescue(function () use ($twin, $d) {
                    SendOutboundMessage::recordSent($twin, $d->externalMessageId);
                    SafeBroadcast::send(new MessageUpdated($twin));
                }, report: true);
            }

            return null;
        }

        // A template or card from another tool that carried no readable text: nothing to show.
        if (trim($d->body) === '' && ! $this->hasRecordableAttachment($d->attachments)) {
            return null;
        }

        try {
            [$message, $pending] = DB::transaction(function () use ($d, $conversation) {
                $message = $conversation->messages()->create([
                    'platform' => $d->platform,
                    'direction' => MessageDirection::Out,
                    'sender_type' => SenderType::User,
                    'user_id' => null,
                    'body' => $d->body !== '' ? $d->body : null,
                    'attachments' => $d->attachments ?: null,
                    'external_id' => $d->externalMessageId,
                    'status' => MessageStatus::Sent,
                    'sent_outside_crm' => true,
                    'sent_at' => $d->occurredAt,
                    // Close cycle H-05: which app sent it (null on Instagram), for EchoPolicy.
                    'echo_app_id' => $d->appId !== null ? mb_substr((string) $d->appId, 0, 40) : null,
                ]);

                // Close cycle Q12 / D-20 for echoes (off until tried on the live page, RC-15): a person's
                // reply from outside the CRM answers her window, or pauses the bot on a bot chat.
                if (EchoPolicy::isHuman($message)) {
                    app(WindowLifecycle::class)->staffEchoed($conversation);
                }

                $pending = $this->attachmentRecorder->record($message, $d->attachments);

                $conversation->forceFill(['last_message_at' => now()])->save();

                return [$message, $pending];
            });
        } catch (UniqueConstraintViolationException) {
            return null; // the same echo delivered twice at once, or our send saved its mid meanwhile
        }

        $message->setRelation('conversation', $conversation);
        $message->load('mediaAttachments');

        SafeBroadcast::send(new MessageCreated($message));
        SafeBroadcast::send(new ConversationUpdated($conversation));

        foreach ($pending as $attachmentId) {
            rescue(function () use ($attachmentId) {
                DownloadInboundMedia::dispatch($attachmentId);
            }, report: true);
        }

        return $message;
    }

    /** @param  array<int, array<string, mixed>>  $attachments */
    private function hasRecordableAttachment(array $attachments): bool
    {
        foreach ($attachments as $a) {
            if (in_array($a['type'] ?? null, self::MEDIA_TYPES, true) && filled($a['url'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One of our own sends this echo mirrors, still without its mid (queued), or a part of one
     * Meta delivers as a separate message: an attachment's caption follows as its own text, and
     * a card send refused as a template falls back to the text with its link lines appended.
     * Any outbound status counts: the media can be delivered, read (Instagram `messaging_seen`)
     * or given up on (a timed-out send Meta did deliver) before its caption's echo is processed.
     */
    private function crmTwin(Conversation $conversation, OutboundEchoData $d): ?Message
    {
        $recent = $conversation->messages()
            ->where('direction', MessageDirection::Out->value)
            ->whereIn('sender_type', [SenderType::User->value, SenderType::Bot->value])
            ->where('sent_outside_crm', false)
            ->where('created_at', '>=', now()->subMinutes(self::TWIN_MINUTES))
            ->withCount('mediaAttachments')
            ->orderByDesc('id')
            ->get(['id', 'conversation_id', 'platform', 'body', 'cards', 'status', 'external_id']);

        $text = trim($d->body);

        return $recent->first(function (Message $m) use ($text) {
            $body = trim((string) $m->body);

            if ($text !== '') {
                return $body !== '' && ($text === $body || (! empty($m->cards) && str_starts_with($text, $body)));
            }

            // A card, carousel or picture echoes without text: only a send still waiting for its mid.
            return $m->status === MessageStatus::Queued && $m->external_id === null
                && (! empty($m->cards) || $m->media_attachments_count > 0);
        });
    }
}
