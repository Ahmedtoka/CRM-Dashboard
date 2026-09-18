<?php

namespace App\Channels\Integrations;

use App\Channels\Adapters\MetaGraphClient;
use App\Channels\Data\SendResult;
use App\Channels\MetaPageSubscriber;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;

/**
 * Instagram Direct + comments through the Instagram professional account linked to
 * the connected Facebook Page (Messenger Platform "Instagram Messaging"): no token of
 * its own — every call borrows the Page's token (ChannelAccount::graphToken()).
 *
 * Webhooks arrive on the app's `instagram` webhook object (configured once in the
 * Meta App Dashboard) for every Page the app is subscribed to, so connecting also
 * (re)subscribes the Page.
 */
final class InstagramConnector
{
    public function __construct(
        private readonly MetaGraphClient $graph,
        private readonly MetaPageSubscriber $subscriber,
    ) {}

    /**
     * The Instagram professional account linked to the Page, or null when none is linked.
     *
     * @return array{id: string, username: ?string, name: ?string, picture: ?string}|null
     *
     * @throws IntegrationException
     */
    public function discover(ChannelAccount $facebook): ?array
    {
        if (blank($facebook->external_id) || blank($facebook->graphToken())) {
            throw new IntegrationException('facebook_not_connected');
        }

        try {
            $response = $this->graph->get($facebook, (string) $facebook->external_id, [
                'fields' => 'instagram_business_account{id,username,profile_picture_url,name}',
            ]);
        } catch (ConnectionException) {
            throw new IntegrationException('graph_unreachable');
        }

        if ($response->failed()) {
            throw IntegrationException::graph((int) $response->json('error.code') === 190 ? 'token_invalid' : 'graph_error', $response->json('error.message'));
        }

        $ig = $response->json('instagram_business_account');

        if (! is_array($ig) || blank($ig['id'] ?? null)) {
            return null;
        }

        return [
            'id' => (string) $ig['id'],
            'username' => $ig['username'] ?? null,
            'name' => $ig['name'] ?? null,
            'picture' => $ig['profile_picture_url'] ?? null,
        ];
    }

    /**
     * Creates/updates the live Instagram account for the Page's linked IG account and
     * subscribes the Page's webhooks.
     *
     * @return array{0: ChannelAccount, 1: SendResult}
     *
     * @throws IntegrationException
     */
    public function connect(ChannelAccount $facebook): array
    {
        $ig = $this->discover($facebook) ?? throw new IntegrationException('instagram_not_linked');

        $account = DB::transaction(function () use ($facebook, $ig) {
            $account = $this->liveAccount(includeDisconnected: true) ?? new ChannelAccount([
                'platform' => Platform::Instagram,
                'driver' => 'live',
            ]);

            $account->fill([
                'name' => $ig['username'] ? '@'.$ig['username'] : ($ig['name'] ?? $ig['id']),
                'external_id' => $ig['id'],
                // Never a token of its own: only which Page's token to borrow.
                'credentials' => ['linked_facebook_account_id' => $facebook->id],
                'profile' => array_filter([
                    'username' => $ig['username'],
                    'display_name' => $ig['name'],
                    'picture' => $ig['picture'],
                    'page_id' => (string) $facebook->external_id,
                ], fn ($v) => $v !== null),
                'status' => 'connected',
                'connected_at' => now(),
                'last_error' => null,
                'health' => null,
                'health_status' => null,
                'health_checked_at' => null,
            ])->save();

            return $account;
        });

        return [$account, $this->subscriber->subscribe($facebook, (string) $facebook->external_id)];
    }

    public function liveAccount(bool $includeDisconnected = false): ?ChannelAccount
    {
        return ChannelAccount::query()
            ->where('platform', Platform::Instagram)
            ->where('driver', 'live')
            ->when(! $includeDisconnected, fn ($q) => $q->where('status', '!=', 'disconnected'))
            ->orderByRaw("case when status = 'disconnected' then 1 else 0 end")
            ->orderBy('id')
            ->first();
    }
}
