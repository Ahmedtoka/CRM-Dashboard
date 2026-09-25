<?php

namespace App\Inbox;

use App\Analytics\ActivityLogger;
use App\Channels\Data\AdReferralData;
use App\Enums\ActorType;
use App\Enums\ConversationSource;
use App\Inbox\Jobs\EnrichAdAttribution;
use App\Models\Conversation;
use Illuminate\Support\Facades\Log;

/**
 * Keeps «which ad she came from» on the conversation (owner, 2026-09-25). The first referral
 * with an ad sets ad_id, the ad's own title and picture (from the webhook, no API needed), marks
 * the source `ad` when the conversation was opened by it, writes a system line in the thread
 * («📣 جات من إعلان: …») and queues the Marketing API lookup for the ad, ad set and campaign
 * names. A later ad on an already-attributed conversation only goes to the activity log, so
 * the first touch is never overwritten.
 */
class AdAttribution
{
    public const SYSTEM_LINE = '📣 العميلة جات من إعلان: %s';

    public const SYSTEM_LINE_REF = '🔗 العميلة جات من لينك: %s';

    public function __construct(private readonly OutboundService $outbound, private readonly ActivityLogger $logger) {}

    public function apply(Conversation $c, AdReferralData $referral, bool $opened): void
    {
        $context = ['source' => $referral->source, 'type' => $referral->type, 'ref' => $referral->ref, 'ad_id' => $referral->adId, 'ad_title' => $referral->adTitle, 'post_id' => $referral->postId];

        if ($c->ad_attributed_at !== null || (! $referral->isAd() && $referral->ref === null)) {
            $this->logger->log(ActorType::System, null, ActivityLogger::CONVERSATION_REFERRAL, $c, $c, $context);

            return;
        }

        $c->forceFill([
            'ad_id' => $referral->adId,
            'ad_title' => $referral->adTitle,
            'ad_post_id' => $referral->postId,
            'ad_photo_url' => $referral->photoUrl,
            'ad_ref' => $referral->ref,
            'ad_attributed_at' => now(),
            // Opened by the ad: the inbox's «من إعلان» filter and badge; a later ad keeps the original source.
            'source' => $opened && $referral->isAd() ? ConversationSource::Ad : $c->source,
        ])->save();

        $this->logger->log(ActorType::System, null, ActivityLogger::CONVERSATION_REFERRAL, $c, $c, $context);

        $label = $referral->isAd() ? sprintf(self::SYSTEM_LINE, $referral->adTitle ?? ('#'.$referral->adId)) : sprintf(self::SYSTEM_LINE_REF, (string) $referral->ref);

        try {
            $this->outbound->sendSystem($c, $label);
        } catch (\Throwable $e) {
            Log::info('ad_attribution.system_line_failed', ['conversation_id' => $c->id, 'error' => $e->getMessage()]);
        }

        if ($referral->adId !== null) {
            EnrichAdAttribution::dispatch($c->id);
        }
    }
}
