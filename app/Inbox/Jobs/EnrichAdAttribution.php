<?php

namespace App\Inbox\Jobs;

use App\Channels\Adapters\MetaGraphClient;
use App\Events\ConversationUpdated;
use App\Models\Conversation;
use App\Support\SafeBroadcast;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The ad, ad set and campaign names behind a conversation's ad_id (2026-09-25), read from the
 * Marketing API with the channel account's own token — which works when that token belongs to a
 * System User with access to the ad account (Le Voile's does). Best effort: a token without
 * ads_read, or an ad the page cannot see, leaves the webhook's ad title in place and stops.
 */
class EnrichAdAttribution implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    /** @var array<int, int> */
    public array $backoff = [60];

    public int $timeout = 30;

    public const FIELDS = 'name,adset{name},campaign{name}';

    public function __construct(public readonly int $conversationId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(MetaGraphClient $graph): void
    {
        $c = Conversation::with('channelAccount')->find($this->conversationId);

        if ($c === null || $c->ad_id === null || $c->channelAccount === null || $c->ad_campaign_name !== null) {
            return;
        }

        try {
            $response = $graph->get($c->channelAccount, $c->ad_id, ['fields' => self::FIELDS]);
        } catch (Throwable $e) {
            Log::info('ad_attribution.lookup_failed', ['conversation_id' => $c->id, 'ad_id' => $c->ad_id, 'error' => $e->getMessage()]);

            return;
        }

        if (! $response->successful()) {
            Log::info('ad_attribution.lookup_refused', ['conversation_id' => $c->id, 'ad_id' => $c->ad_id, 'status' => $response->status(), 'error' => $response->json('error.message')]);

            return;
        }

        $c->forceFill([
            'ad_name' => $this->cut($response->json('name')),
            'ad_adset_name' => $this->cut($response->json('adset.name')),
            'ad_campaign_name' => $this->cut($response->json('campaign.name')),
        ])->save();

        SafeBroadcast::send(new ConversationUpdated($c));
    }

    private function cut(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 190) : null;
    }
}
