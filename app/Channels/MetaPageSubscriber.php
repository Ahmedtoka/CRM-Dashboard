<?php

namespace App\Channels;

use App\Channels\Adapters\MetaGraphClient;
use App\Channels\Data\SendResult;
use App\Models\ChannelAccount;

/**
 * Subscribes a Facebook page to the webhook fields the live Messenger/Instagram
 * adapters rely on (`POST /{page-id}/subscribed_apps`). Shared by the manual
 * "Subscribe to webhooks" button and the "Connect with Facebook" flow.
 *
 * The call is authorised with `$account`'s Graph token — for an Instagram account
 * that is its linked Facebook page's token (ChannelAccount::graphToken()).
 */
class MetaPageSubscriber
{
    /**
     * `messaging_referrals` (2026-09-25): which ad or m.me link a conversation came through.
     * `message_echoes` (2026-10-17): replies sent from Meta Business Suite or the phone app
     * (App\Inbox\EchoIngestor); an existing page gets it when the owner subscribes again.
     */
    public const FIELDS = 'messages,messaging_postbacks,messaging_referrals,message_deliveries,message_reads,message_echoes,feed';

    public function __construct(private readonly MetaGraphClient $graph) {}

    public function subscribe(ChannelAccount $account, string $pageId): SendResult
    {
        return $this->graph->post($account, "{$pageId}/subscribed_apps", [
            'subscribed_fields' => self::FIELDS,
        ]);
    }
}
