<?php

namespace App\Channels\Adapters\Concerns;

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
                    externalMessageId: 'postback:'.($item['sender']['id'] ?? '').':'.($item['timestamp'] ?? ''),
                    body: $item['postback']['title'] ?? '',
                    occurredAt: $this->fromMsTimestamp($item['timestamp'] ?? 0),
                    payload: isset($item['postback']['payload']) ? (string) $item['postback']['payload'] : null,
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
                $watermark = $item['read']['watermark'] ?? 0;
                $events[] = new DeliveryReceiptData(
                    platform: $platform,
                    externalMessageId: (string) $watermark,
                    status: MessageStatus::Read,
                    occurredAt: $this->fromMsTimestamp($watermark),
                );
            }
        }

        return $events;
    }

    protected function fromMsTimestamp(int|string $timestampMs): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp(intdiv((int) $timestampMs, 1000));
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
