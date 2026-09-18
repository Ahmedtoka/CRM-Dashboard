<?php

namespace App\Channels\Adapters;

use App\Channels\Adapters\Concerns\VerifiesMetaWebhooks;
use App\Channels\Contracts\ChannelAdapter;
use App\Channels\Data\ChannelCapabilities;
use App\Channels\Data\DeliveryReceiptData;
use App\Channels\Data\InboundMessageData;
use App\Channels\Data\SendResult;
use App\Enums\AttachmentType;
use App\Enums\MessageStatus;
use App\Enums\Platform;
use App\Media\MediaPolicy;
use App\Media\VoiceTranscoder;
use App\Models\ChannelAccount;
use App\Models\CustomerIdentity;
use App\Models\MessageAttachment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsAppAdapter implements ChannelAdapter
{
    use VerifiesMetaWebhooks;

    /**
     * A voice recording's real container can sniff as either mime depending on
     * the recording browser/finfo version — some environments report a webm
     * audio-only track as "video/webm" rather than "audio/webm" even though the
     * attachment was already typed Audio at upload time. Both must trigger the
     * same ogg/opus transcode decision below.
     */
    private const WEBM_AUDIO_MIMES = ['audio/webm', 'video/webm'];

    public function __construct(private readonly MetaGraphClient $graph, private readonly VoiceTranscoder $transcoder = new VoiceTranscoder) {}

    public function platform(): Platform
    {
        return Platform::WhatsApp;
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            privateReply: false,
            hideComment: false,
            windowHours: 24,
            humanAgentHours: 0,
            templatesOutsideWindow: true,
        );
    }

    public function normalize(array $payload): array
    {
        $events = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? null) !== 'messages') {
                    continue;
                }

                $value = $change['value'] ?? [];
                $channelExternalId = (string) ($value['metadata']['phone_number_id'] ?? '');
                $contactsByWaId = collect($value['contacts'] ?? [])->keyBy('wa_id');

                foreach ($value['messages'] ?? [] as $message) {
                    $normalized = $this->normalizeMessage($message, $contactsByWaId, $channelExternalId);

                    if ($normalized !== null) {
                        $events[] = $normalized;
                    }
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    $error = $status['errors'][0] ?? null;

                    $events[] = new DeliveryReceiptData(
                        platform: $this->platform(),
                        externalMessageId: (string) ($status['id'] ?? ''),
                        status: $this->mapStatus($status['status'] ?? ''),
                        occurredAt: CarbonImmutable::createFromTimestamp((int) ($status['timestamp'] ?? 0)),
                        error: is_array($error)
                            ? trim('('.($error['code'] ?? '?').') '.($error['error_data']['details'] ?? $error['message'] ?? $error['title'] ?? ''))
                            : null,
                    );
                }
            }
        }

        return $events;
    }

    /**
     * One Cloud API `messages[]` item. Returns null for events that are not a new
     * customer message: a reaction to an earlier message, and `system`/`request_welcome`
     * notices.
     *
     * @param  array<string, mixed>  $message
     * @param  Collection<string, array<string, mixed>>  $contactsByWaId
     */
    private function normalizeMessage(array $message, Collection $contactsByWaId, string $channelExternalId): ?InboundMessageData
    {
        $from = (string) ($message['from'] ?? '');
        $contact = $contactsByWaId->get($from) ?? $contactsByWaId->first();

        $type = $message['type'] ?? 'text';

        if (in_array($type, ['reaction', 'system', 'request_welcome', 'ephemeral'], true)) {
            return null;
        }

        $body = (string) ($message['text']['body'] ?? '');
        $attachments = [];
        $payload = null;

        if ($type === 'sticker') {
            $attachments[] = ['type' => 'sticker', 'id' => (string) ($message['sticker']['id'] ?? '')];
        } elseif (in_array($type, ['image', 'audio', 'video', 'document'], true)) {
            $media = $message[$type] ?? [];
            $attachments[] = array_filter([
                'type' => $type === 'document' ? 'file' : $type,
                'id' => (string) ($media['id'] ?? ''),
                'mime_type' => $media['mime_type'] ?? null,
                'filename' => $media['filename'] ?? null,
                'voice' => $type === 'audio' && isset($media['voice']) ? (bool) $media['voice'] : null,
            ], fn ($v) => $v !== null);
            $body = (string) ($media['caption'] ?? '');
        } elseif ($type === 'interactive') {
            // A tap on one of our reply buttons / list rows: the title is what the
            // customer "said", the id is the bot payload we sent with it.
            $reply = $message['interactive']['button_reply'] ?? $message['interactive']['list_reply'] ?? [];
            $body = (string) ($reply['title'] ?? '');
            $payload = isset($reply['id']) && $reply['id'] !== '' ? (string) $reply['id'] : null;
        } elseif ($type === 'button') {
            // A quick-reply button on a template message.
            $body = (string) ($message['button']['text'] ?? '');
            $payload = isset($message['button']['payload']) && $message['button']['payload'] !== '' ? (string) $message['button']['payload'] : null;
        } elseif ($type === 'location') {
            $location = $message['location'] ?? [];
            $lat = $location['latitude'] ?? null;
            $lng = $location['longitude'] ?? null;
            $place = trim(($location['name'] ?? '').' '.($location['address'] ?? ''));
            $body = trim(implode("\n", array_filter([
                '📍 '.($place !== '' ? $place : 'Location'),
                $lat !== null && $lng !== null ? "https://maps.google.com/?q={$lat},{$lng}" : null,
            ])));
        } elseif ($type === 'contacts') {
            $body = collect($message['contacts'] ?? [])
                ->map(fn (array $c) => trim(($c['name']['formatted_name'] ?? '').' '.collect($c['phones'] ?? [])->pluck('phone')->filter()->implode(' ')))
                ->filter()
                ->map(fn (string $line) => '👤 '.$line)
                ->implode("\n");
        }

        return new InboundMessageData(
            platform: $this->platform(),
            channelExternalId: $channelExternalId,
            customerExternalId: $from,
            customerName: $contact['profile']['name'] ?? '',
            externalMessageId: (string) ($message['id'] ?? ''),
            body: $body,
            occurredAt: CarbonImmutable::createFromTimestamp((int) ($message['timestamp'] ?? 0)),
            attachments: $attachments,
            customerPhone: $from,
            payload: $payload,
        );
    }

    private function mapStatus(string $status): MessageStatus
    {
        return match ($status) {
            'sent' => MessageStatus::Sent,
            'delivered' => MessageStatus::Delivered,
            'read' => MessageStatus::Read,
            'failed' => MessageStatus::Failed,
            default => MessageStatus::Sent,
        };
    }

    public function sendText(ChannelAccount $account, CustomerIdentity $to, string $text, array $options = []): SendResult
    {
        $phoneNumberId = $account->external_id;

        if (isset($options['template'])) {
            $template = $options['template'];
            $params = $template['params'] ?? [];
            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to->external_id,
                'type' => 'template',
                'template' => [
                    'name' => $template['name'],
                    'language' => ['code' => $template['language']],
                ],
            ];

            // A template without variables takes no components at all: an empty body
            // `parameters` list is rejected by the Cloud API (parameter count mismatch).
            if ($params !== []) {
                $payload['template']['components'] = [[
                    'type' => 'body',
                    'parameters' => array_map(fn ($param) => ['type' => 'text', 'text' => (string) $param], array_values($params)),
                ]];
            }
        } elseif (! empty($options['quick_replies']) && ($interactive = $this->interactive($text, $options['quick_replies'])) !== null) {
            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to->external_id,
                'type' => 'interactive',
                'interactive' => $interactive,
            ];
        } else {
            // Buttons that do not fit WhatsApp's interactive limits fall back to a
            // numbered list the customer answers by typing the number (ButtonMatcher).
            if (! empty($options['quick_replies'])) {
                $lines = array_map(fn (int $i, array $b) => ($i + 1).'- '.$b['title'], array_keys($options['quick_replies']), $options['quick_replies']);
                $text .= "\n\n".implode("\n", $lines);
            }

            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to->external_id,
                'type' => 'text',
                'text' => ['body' => $text],
            ];
        }

        return $this->graph->post($account, "{$phoneNumberId}/messages", $payload);
    }

    /**
     * Bot buttons as a WhatsApp interactive message: up to 3 become reply buttons
     * (title <= 20 chars), up to 10 a list (row title <= 24 chars). Null when they do
     * not fit (more than 10, a longer title, a body over 1024 chars): the caller then
     * sends numbered text. Titles are never cut: a truncated option reads worse than
     * typing a number.
     *
     * @param  array<int, array{title: string, payload: string}>  $buttons
     * @return array<string, mixed>|null
     */
    private function interactive(string $text, array $buttons): ?array
    {
        $buttons = array_values($buttons);
        $body = trim($text) === '' ? '…' : $text;

        if (mb_strlen($body) > 1024 || count($buttons) > 10) {
            return null;
        }

        $fits = fn (int $max) => collect($buttons)->every(
            fn (array $b) => mb_strlen((string) $b['title']) <= $max && mb_strlen((string) $b['payload']) <= 200,
        );

        if (count($buttons) <= 3 && $fits(20)) {
            return [
                'type' => 'button',
                'body' => ['text' => $body],
                'action' => ['buttons' => array_map(fn (array $b) => [
                    'type' => 'reply',
                    'reply' => ['id' => (string) $b['payload'], 'title' => (string) $b['title']],
                ], $buttons)],
            ];
        }

        if (! $fits(24)) {
            return null;
        }

        return [
            'type' => 'list',
            'body' => ['text' => $body],
            'action' => [
                'button' => (string) config('crm.whatsapp_list_button', 'الاختيارات'),
                'sections' => [[
                    'rows' => array_map(fn (array $b) => ['id' => (string) $b['payload'], 'title' => (string) $b['title']], $buttons),
                ]],
            ],
        ];
    }

    /**
     * @param  array{tag?: string}  $options
     */
    public function sendAttachment(ChannelAccount $account, CustomerIdentity $to, MessageAttachment $attachment, ?string $caption = null, array $options = []): SendResult
    {
        $file = $attachment->absolutePath();
        $mime = (string) $attachment->mime;
        $cleanup = null;

        if ($attachment->type === AttachmentType::Audio && in_array($mime, self::WEBM_AUDIO_MIMES, true)) {
            $converted = $this->transcoder->toOggOpus($file);

            if ($converted === null) {
                Log::warning('whatsapp.voice_unsupported', ['attachment_id' => $attachment->id]);

                return SendResult::fail(MediaPolicy::WHATSAPP_VOICE_UNSUPPORTED);
            }

            [$file, $mime, $cleanup] = [$converted, 'audio/ogg', $converted];
        }

        try {
            $name = $attachment->original_name ?: basename($file);
            $upload = $this->graph->upload($account, "{$account->external_id}/media", $file, $mime, $name);

            if (! $upload->success) {
                return $upload;
            }

            $waType = match ($attachment->type) {
                AttachmentType::Image => 'image',
                AttachmentType::Video => 'video',
                AttachmentType::Audio => 'audio',
                AttachmentType::Sticker => 'sticker',
                AttachmentType::File => 'document',
            };

            $media = ['id' => $upload->externalId];
            $captionable = in_array($waType, ['image', 'video', 'document'], true);

            if ($caption !== null && $caption !== '' && $captionable) {
                $media['caption'] = $caption;
            }

            if ($waType === 'document') {
                $media['filename'] = $name;
            }

            $result = $this->graph->post($account, "{$account->external_id}/messages", [
                'messaging_product' => 'whatsapp', 'to' => $to->external_id, 'type' => $waType, $waType => $media,
            ]);

            // audio/sticker carry no caption field: it follows as its own text message.
            // Never let a caption problem fail or resend the media itself — the media
            // POST above already succeeded. postFast() (10s, single try) rather than
            // sendText()'s shared client (10s × up to 2 tries): this call already runs
            // after the ffmpeg transcode + media upload + media send have spent most of
            // the job's 80s budget, so it cannot afford another ~20s worst case here.
            if ($result->success && $caption !== null && $caption !== '' && ! $captionable) {
                try {
                    $captionResult = $this->graph->postFast($account, "{$account->external_id}/messages", [
                        'messaging_product' => 'whatsapp', 'to' => $to->external_id, 'type' => 'text', 'text' => ['body' => $caption],
                    ]);

                    if (! $captionResult->success) {
                        Log::warning('whatsapp.caption_send_failed', ['attachment_id' => $attachment->id, 'error' => $captionResult->error]);
                    }
                } catch (Throwable $e) {
                    // Exception class only — never the message (may embed a request url).
                    Log::warning('whatsapp.caption_send_threw', ['attachment_id' => $attachment->id, 'exception' => $e::class]);
                }
            }

            return $result;
        } finally {
            if ($cleanup !== null) {
                @unlink($cleanup);
            }
        }
    }

    public function typing(ChannelAccount $account, CustomerIdentity $to, bool $on): void
    {
        // No typing indicator on the WhatsApp Cloud API.
    }

    public function replyToComment(ChannelAccount $account, string $commentExternalId, string $text): SendResult
    {
        return SendResult::fail('not_supported_on_whatsapp');
    }

    public function hideComment(ChannelAccount $account, string $commentExternalId): SendResult
    {
        return SendResult::fail('not_supported_on_whatsapp');
    }

    public function sendPrivateReply(ChannelAccount $account, string $commentExternalId, string $text): SendResult
    {
        return SendResult::fail('not_supported_on_whatsapp');
    }
}
