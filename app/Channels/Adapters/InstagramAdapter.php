<?php

namespace App\Channels\Adapters;

use App\Channels\Adapters\Concerns\NormalizesMetaMessaging;
use App\Channels\Adapters\Concerns\SendsMetaAttachments;
use App\Channels\Adapters\Concerns\SendsMetaCards;
use App\Channels\Adapters\Concerns\VerifiesMetaWebhooks;
use App\Channels\Cards\OutboundCards;
use App\Channels\Contracts\ChannelAdapter;
use App\Channels\Data\ChannelCapabilities;
use App\Channels\Data\InboundCommentData;
use App\Channels\Data\SendResult;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\CustomerIdentity;

class InstagramAdapter implements ChannelAdapter
{
    use NormalizesMetaMessaging;
    use SendsMetaAttachments;
    use SendsMetaCards;
    use VerifiesMetaWebhooks;

    public function __construct(private readonly MetaGraphClient $graph) {}

    public function platform(): Platform
    {
        return Platform::Instagram;
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
            $entryOccurredAt = $this->fromMetaTimestamp($entry['time'] ?? 0);

            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? null) !== 'comments') {
                    continue;
                }

                $value = $change['value'] ?? [];

                // Ignore comments authored by the IG business account itself (auto-replies,
                // moderator comments echoed back through the same webhook).
                if ((string) ($value['from']['id'] ?? '') === $channelExternalId) {
                    continue;
                }

                $events[] = new InboundCommentData(
                    platform: $this->platform(),
                    channelExternalId: $channelExternalId,
                    postExternalId: (string) ($value['media']['id'] ?? ''),
                    commentExternalId: (string) ($value['id'] ?? ''),
                    customerExternalId: (string) ($value['from']['id'] ?? ''),
                    customerName: $value['from']['username'] ?? '',
                    body: $value['text'] ?? '',
                    occurredAt: $entryOccurredAt,
                    parentExternalId: $value['parent_id'] ?? null,
                );
            }
        }

        return $events;
    }

    public function sendText(ChannelAccount $account, CustomerIdentity $to, string $text, array $options = []): SendResult
    {
        // Rich cards (2026-09-19): a template, falling back to this plain text when refused.
        if (($cards = OutboundCards::valid($options['cards'] ?? null)) !== null) {
            return $this->sendCards($account, $to, $text, $cards, $options);
        }

        return $this->sendPlainText($account, $to, $text, $options);
    }

    protected function sendPlainText(ChannelAccount $account, CustomerIdentity $to, string $text, array $options): SendResult
    {
        $payload = [
            'recipient' => ['id' => $to->external_id],
            'message' => ['text' => $text],
            'messaging_type' => isset($options['tag']) ? 'MESSAGE_TAG' : 'RESPONSE',
        ];

        if (isset($options['tag'])) {
            $payload['tag'] = $options['tag'];
        }

        if (! empty($options['quick_replies'])) {
            $payload['message']['quick_replies'] = $this->metaQuickReplies($options['quick_replies']);
        }

        return $this->postMessage($account, $payload);
    }

    protected function postMessage(ChannelAccount $account, array $payload): SendResult
    {
        return $this->graph->post($account, $this->messagesEndpoint($account), $payload);
    }

    /** Instagram has no call button: the branch phone stays in the card subtitle. */
    protected function supportsCallButtons(): bool
    {
        return false;
    }

    /** Best effort on the fast path (final fix wave I9): one 3 s try, never the send client's retries. */
    public function typing(ChannelAccount $account, CustomerIdentity $to, bool $on): void
    {
        rescue(fn () => $this->graph->postFast($account, $this->messagesEndpoint($account), [
            'recipient' => ['id' => $to->external_id],
            'sender_action' => $on ? 'typing_on' : 'typing_off',
        ], 3), report: false);
    }

    /**
     * Instagram messaging via the Messenger Platform is sent through the *linked
     * Facebook Page*: `POST /{page-id}/messages` with that Page's access token (the
     * documented form; `me/messages` only resolves to the same Page by accident of
     * the token type).
     */
    protected function messagesEndpoint(ChannelAccount $account): string
    {
        $pageId = $account->linkedFacebookAccount()?->external_id;

        return filled($pageId) ? "{$pageId}/messages" : 'me/messages';
    }

    public function replyToComment(ChannelAccount $account, string $commentExternalId, string $text): SendResult
    {
        return $this->graph->post($account, "{$commentExternalId}/replies", ['message' => $text]);
    }

    public function hideComment(ChannelAccount $account, string $commentExternalId): SendResult
    {
        return $this->graph->post($account, $commentExternalId, ['hide' => true]);
    }

    public function sendPrivateReply(ChannelAccount $account, string $commentExternalId, string $text): SendResult
    {
        $payload = [
            'recipient' => ['comment_id' => $commentExternalId],
            'message' => ['text' => $text],
        ];

        return $this->graph->post($account, $this->messagesEndpoint($account), $payload);
    }
}
