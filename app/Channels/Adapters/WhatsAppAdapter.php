<?php

namespace App\Channels\Adapters;

use App\Channels\Adapters\Concerns\VerifiesMetaWebhooks;
use App\Channels\Contracts\ChannelAdapter;
use App\Channels\Data\ChannelCapabilities;
use App\Channels\Data\DeliveryReceiptData;
use App\Channels\Data\InboundMessageData;
use App\Channels\Data\SendResult;
use App\Enums\MessageStatus;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\CustomerIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class WhatsAppAdapter implements ChannelAdapter
{
    use VerifiesMetaWebhooks;

    public function __construct(private readonly MetaGraphClient $graph) {}

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
                    $events[] = $this->normalizeMessage($message, $contactsByWaId, $channelExternalId);
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    $events[] = new DeliveryReceiptData(
                        platform: $this->platform(),
                        externalMessageId: (string) ($status['id'] ?? ''),
                        status: $this->mapStatus($status['status'] ?? ''),
                        occurredAt: CarbonImmutable::createFromTimestamp((int) ($status['timestamp'] ?? 0)),
                    );
                }
            }
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  Collection<string, array<string, mixed>>  $contactsByWaId
     */
    private function normalizeMessage(array $message, Collection $contactsByWaId, string $channelExternalId): InboundMessageData
    {
        $from = (string) ($message['from'] ?? '');
        $contact = $contactsByWaId->get($from);

        $attachments = [];
        if (($message['type'] ?? null) === 'sticker') {
            $attachments[] = ['type' => 'sticker', 'id' => (string) ($message['sticker']['id'] ?? '')];
        }

        return new InboundMessageData(
            platform: $this->platform(),
            channelExternalId: $channelExternalId,
            customerExternalId: $from,
            customerName: $contact['profile']['name'] ?? '',
            externalMessageId: (string) ($message['id'] ?? ''),
            body: $message['text']['body'] ?? '',
            occurredAt: CarbonImmutable::createFromTimestamp((int) ($message['timestamp'] ?? 0)),
            attachments: $attachments,
            customerPhone: $from,
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
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $to->external_id,
                'type' => 'template',
                'template' => [
                    'name' => $template['name'],
                    'language' => ['code' => $template['language']],
                    'components' => [[
                        'type' => 'body',
                        'parameters' => array_map(
                            fn ($param) => ['type' => 'text', 'text' => (string) $param],
                            $template['params'] ?? [],
                        ),
                    ]],
                ],
            ];
        } else {
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $to->external_id,
                'type' => 'text',
                'text' => ['body' => $text],
            ];
        }

        return $this->graph->post($account, "{$phoneNumberId}/messages", $payload);
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
