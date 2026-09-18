<?php

namespace App\Channels\Integrations;

use App\Channels\Adapters\MetaGraphClient;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Disconnect from Settings → Integrations: unsubscribe the app from the Page / WABA
 * (best effort — a dead token must never block a disconnect), then mark the account
 * disconnected and clear its credentials. Conversations, comments and customers stay.
 *
 * Instagram borrows the Facebook Page's token and webhook subscription, so:
 *  - disconnecting Facebook also disconnects the Instagram account linked to it;
 *  - disconnecting Instagram leaves the Page subscribed (Messenger still needs it).
 */
final class ChannelDisconnector
{
    public function __construct(private readonly MetaGraphClient $graph) {}

    /**
     * @return list<int> ids of every account that was disconnected
     */
    public function disconnect(ChannelAccount $account): array
    {
        $ids = [];

        if ($account->platform === Platform::Facebook) {
            $this->unsubscribe($account, (string) $account->external_id);

            ChannelAccount::query()
                ->where('platform', Platform::Instagram)
                ->where('status', '!=', 'disconnected')
                ->get()
                ->filter(fn (ChannelAccount $ig) => (int) ($ig->credentials['linked_facebook_account_id'] ?? 0) === $account->id)
                ->each(function (ChannelAccount $ig) use (&$ids) {
                    $this->markDisconnected($ig);
                    $ids[] = $ig->id;
                });
        }

        if ($account->platform === Platform::WhatsApp && $account->wabaId() !== null) {
            $this->unsubscribe($account, $account->wabaId());
        }

        $this->markDisconnected($account);
        $ids[] = $account->id;

        return $ids;
    }

    private function unsubscribe(ChannelAccount $account, string $objectId): void
    {
        $token = $account->graphToken();

        if ($objectId === '' || blank($token) || ! $account->isLive()) {
            return;
        }

        try {
            $result = $this->graph->deleteWithToken((string) $token, "{$objectId}/subscribed_apps");

            if (! $result->success) {
                Log::warning('integrations.unsubscribe_failed', ['account_id' => $account->id, 'error' => $result->error]);
            }
        } catch (Throwable $e) {
            Log::warning('integrations.unsubscribe_threw', ['account_id' => $account->id, 'exception' => $e::class]);
        }
    }

    private function markDisconnected(ChannelAccount $account): void
    {
        $account->forceFill([
            'status' => 'disconnected',
            'credentials' => null,
            'health' => null,
            'health_status' => null,
            'health_checked_at' => null,
            'last_error' => null,
        ])->save();
    }
}
