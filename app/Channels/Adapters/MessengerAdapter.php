<?php

namespace App\Channels\Adapters;

use App\Channels\Adapters\Concerns\NormalizesMetaMessaging;
use App\Channels\Adapters\Concerns\VerifiesMetaWebhooks;
use App\Channels\Contracts\ChannelAdapter;
use App\Channels\Data\ChannelCapabilities;
use App\Channels\Data\InboundCommentData;
use App\Channels\Data\SendResult;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\CustomerIdentity;
use Carbon\CarbonImmutable;

class MessengerAdapter implements ChannelAdapter
{
    use NormalizesMetaMessaging;
    use VerifiesMetaWebhooks;

    public function __construct(private readonly MetaGraphClient $graph) {}

    public function platform(): Platform
    {
        return Platform::Facebook;
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            privateReply: true,
            hideComment: true,
            windowHours: 24,
            humanAgentHours: 168,
            templatesOutsideWindow: false,
        );
    }

    public function normalize(array $payload): array
    {
        $events = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            $events = array_merge($events, $this->normalizeMessaging($entry, $this->platform()));

            $channelExternalId = (string) ($entry['id'] ?? '');

            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? null) !== 'feed') {
                    continue;
                }

                $value = $change['value'] ?? [];

                if (($value['item'] ?? null) !== 'comment' || ($value['verb'] ?? 'add') !== 'add') {
                    continue;
                }

                // The page's own comments (auto-replies, moderator comments) come back
                // through the same webhook; ignore them to avoid replying to ourselves.
                if ((string) ($value['from']['id'] ?? '') === $channelExternalId) {
                    continue;
                }

                $events[] = new InboundCommentData(
                    platform: $this->platform(),
                    channelExternalId: $channelExternalId,
                    postExternalId: (string) ($value['post_id'] ?? ''),
                    commentExternalId: (string) ($value['comment_id'] ?? ''),
                    customerExternalId: (string) ($value['from']['id'] ?? ''),
                    customerName: $value['from']['name'] ?? '',
                    body: $value['message'] ?? '',
                    occurredAt: CarbonImmutable::createFromTimestamp((int) ($value['created_time'] ?? 0)),
                );
            }
        }

        return $events;
    }

    public function sendText(ChannelAccount $account, CustomerIdentity $to, string $text, array $options = []): SendResult
    {
        $payload = [
            'recipient' => ['id' => $to->external_id],
            'message' => ['text' => $text],
            'messaging_type' => isset($options['tag']) ? 'MESSAGE_TAG' : 'RESPONSE',
        ];

        if (isset($options['tag'])) {
            $payload['tag'] = $options['tag'];
        }

        return $this->graph->post($account, 'me/messages', $payload);
    }

    public function replyToComment(ChannelAccount $account, string $commentExternalId, string $text): SendResult
    {
        return $this->graph->post($account, "{$commentExternalId}/comments", ['message' => $text]);
    }

    public function hideComment(ChannelAccount $account, string $commentExternalId): SendResult
    {
        return $this->graph->post($account, $commentExternalId, ['is_hidden' => true]);
    }

    public function sendPrivateReply(ChannelAccount $account, string $commentExternalId, string $text): SendResult
    {
        $payload = [
            'recipient' => ['comment_id' => $commentExternalId],
            'message' => ['text' => $text],
        ];

        return $this->graph->post($account, 'me/messages', $payload);
    }
}
