<?php

namespace App\Channels\Adapters\Concerns;

use App\Channels\Data\AdReferralData;
use App\Channels\Data\DeliveryReceiptData;
use App\Channels\Data\InboundMessageData;
use App\Enums\MessageStatus;
use App\Enums\Platform;
use Carbon\CarbonImmutable;

/**
 * Shared `messaging[]` parsing for Messenger and Instagram Direct, which
 * both use the same Send/Receive API envelope (sender/recipient/message,
 * delivery, read).
 */
trait NormalizesMetaMessaging
{
    /**
     * @param  array<string, mixed>  $entry
     * @return array<int, InboundMessageData|DeliveryReceiptData>
     */
    protected function normalizeMessaging(array $entry, Platform $platform): array
    {
        $events = [];
        $channelExternalId = (string) ($entry['id'] ?? '');

        foreach ($entry['messaging'] ?? [] as $item) {
            if (isset($item['message'])) {
                // Echoes are the page's own outgoing messages mirrored back on the webhook;
                // they were already recorded when we sent them, so they are dropped here.
                if ($item['message']['is_echo'] ?? false) {
                    continue;
                }

                // Instagram: a message the customer unsent arrives again flagged
                // `is_deleted` (same mid, no content) — never a new message.
                if ($item['message']['is_deleted'] ?? false) {
                    continue;
                }

                $events[] = new InboundMessageData(
                    platform: $platform,
                    channelExternalId: $channelExternalId,
                    customerExternalId: (string) ($item['sender']['id'] ?? ''),
                    customerName: $item['sender']['name'] ?? '',
                    externalMessageId: (string) ($item['message']['mid'] ?? ''),
                    body: $item['message']['text'] ?? '',
                    occurredAt: $this->fromMsTimestamp($item['timestamp'] ?? 0),
                    attachments: $this->mapAttachments($item['message']),
                    payload: isset($item['message']['quick_reply']['payload']) ? (string) $item['message']['quick_reply']['payload'] : null,
                    // A Click-to-Messenger ad puts the referral on the first message itself.
                    referral: $this->referralOf($item['message']['referral'] ?? $item['referral'] ?? null),
                );

                continue;
            }

            if (isset($item['postback'])) {
                // Structured-message button taps carry no message id in Meta's payload;
                // synthesize a stable one from sender + timestamp so dedup still works.
                $events[] = new InboundMessageData(
                    platform: $platform,
                    channelExternalId: $channelExternalId,
                    customerExternalId: (string) ($item['sender']['id'] ?? ''),
                    customerName: $item['sender']['name'] ?? '',
                    // Instagram postbacks carry their own `mid`; Messenger's do not.
                    externalMessageId: isset($item['postback']['mid']) && $item['postback']['mid'] !== ''
                        ? (string) $item['postback']['mid']
                        : 'postback:'.($item['sender']['id'] ?? '').':'.($item['timestamp'] ?? ''),
                    body: $item['postback']['title'] ?? '',
                    occurredAt: $this->fromMsTimestamp($item['timestamp'] ?? 0),
                    payload: isset($item['postback']['payload']) ? (string) $item['postback']['payload'] : null,
                    referral: $this->referralOf($item['postback']['referral'] ?? null),
                );

                continue;
            }

            // `messaging_referrals` on its own (an existing thread opened again from an ad or an
            // m.me link): nothing was said, so it rides an empty message the ingestor only reads
            // the attribution from (InboxIngestor::ingestMessage).
            if (isset($item['referral']) && ($referral = $this->referralOf($item['referral'])) !== null) {
                $events[] = new InboundMessageData(
                    platform: $platform,
                    channelExternalId: $channelExternalId,
                    customerExternalId: (string) ($item['sender']['id'] ?? ''),
                    customerName: $item['sender']['name'] ?? '',
                    externalMessageId: 'referral:'.($item['sender']['id'] ?? '').':'.($item['timestamp'] ?? ''),
                    body: '',
                    occurredAt: $this->fromMsTimestamp($item['timestamp'] ?? 0),
                    referral: $referral,
                );

                continue;
            }

            if (isset($item['delivery'])) {
                $occurredAt = $this->fromMsTimestamp($item['delivery']['watermark'] ?? 0);

                foreach ($item['delivery']['mids'] ?? [] as $mid) {
                    $events[] = new DeliveryReceiptData(
                        platform: $platform,
                        externalMessageId: (string) $mid,
                        status: MessageStatus::Delivered,
                        occurredAt: $occurredAt,
                    );
                }

                continue;
            }

            if (isset($item['read'])) {
                // Instagram (`messaging_seen`) names the message read: {read: {mid}}.
                // Messenger only sends a watermark timestamp, which matches no message id.
                $mid = $item['read']['mid'] ?? null;
                $watermark = $item['read']['watermark'] ?? 0;
                $events[] = new DeliveryReceiptData(
                    platform: $platform,
                    externalMessageId: is_string($mid) && $mid !== '' ? $mid : (string) $watermark,
                    status: MessageStatus::Read,
                    occurredAt: is_string($mid) && $mid !== ''
                        ? $this->fromMsTimestamp($item['timestamp'] ?? 0)
                        : $this->fromMsTimestamp($watermark),
                );
            }
        }

        return $events;
    }

    protected function referralOf(mixed $referral): ?AdReferralData
    {
        return is_array($referral) ? AdReferralData::fromMeta($referral) : null;
    }

    protected function fromMsTimestamp(int|string $timestampMs): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp(intdiv((int) $timestampMs, 1000));
    }

    /**
     * Meta is inconsistent about `entry.time`: milliseconds on messaging entries but
     * seconds on Instagram `changes` (comments) entries. Anything below 10^11 is read
     * as seconds (10^11 s is the year 5138; 10^11 ms is 1973).
     */
    protected function fromMetaTimestamp(int|string $timestamp): CarbonImmutable
    {
        $value = (int) $timestamp;

        return $value >= 100_000_000_000
            ? CarbonImmutable::createFromTimestamp(intdiv($value, 1000))
            : CarbonImmutable::createFromTimestamp($value);
    }

    /**
     * Media download/display is a later sub-project; for now attachments are kept
     * as a flat {type, url} list (image/video/audio/file/story_mention/...). A
     * sticker send (e.g. the built-in thumbs-up) arrives as an `image` attachment
     * carrying `payload.sticker_id` — sometimes mirrored on `message.sticker_id`
     * too — and is reported as its own `sticker` type so the spam classifier can
     * treat it as content-free like other emoji-only replies.
     *
     * @param  array<string, mixed>  $message
     * @return array<int, array{type: string, url: ?string, sticker_id?: string}>
     */
    protected function mapAttachments(array $message): array
    {
        $attachments = $message['attachments'] ?? [];
        $messageStickerId = $message['sticker_id'] ?? null;

        // A message-level sticker_id only unambiguously identifies a single attachment
        // (or none at all, handled below); with several attachments it's ignored rather
        // than guessed onto one of them.
        $applyMessageStickerId = $messageStickerId !== null && count($attachments) <= 1;

        $mapped = array_map(function (array $a) use ($messageStickerId, $applyMessageStickerId) {
            $stickerId = $a['payload']['sticker_id'] ?? ($applyMessageStickerId ? $messageStickerId : null);

            if ($stickerId !== null) {
                return ['type' => 'sticker', 'url' => $a['payload']['url'] ?? null, 'sticker_id' => (string) $stickerId];
            }

            return ['type' => $a['type'] ?? 'file', 'url' => $a['payload']['url'] ?? null];
        }, $attachments);

        if ($mapped === [] && $messageStickerId !== null) {
            $mapped[] = ['type' => 'sticker', 'url' => null, 'sticker_id' => (string) $messageStickerId];
        }

        return $mapped;
    }
}
