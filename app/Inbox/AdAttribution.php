<?php

namespace App\Inbox;

use App\Analytics\ActivityLogger;
use App\Channels\Data\AdReferralData;
use App\Enums\ActorType;
use App\Enums\ConversationSource;
use App\Inbox\Jobs\EnrichAdAttribution;
use App\Models\ActivityLog;
use App\Models\Conversation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keeps «which ad she came from» on the conversation (owner, 2026-09-25). The first referral
 * with an ad sets ad_id, the ad's own title and picture (from the webhook, no API needed), marks
 * the source `ad` when the conversation was opened by it, writes a system line in the thread
 * («جات من إعلان: …») and queues the Marketing API lookup for the ad, ad set and campaign
 * names. A later ad on an already-attributed conversation only goes to the activity log, so
 * the first touch is never overwritten.
 *
 * Every ad referral (first or later) is also kept in conversation_ad_referrals (A4), stamped with the
 * activity-log row's created_at so `ads:backfill-referrals` rebuilds exactly the same rows.
 */
class AdAttribution
{
    public const SYSTEM_LINE = 'العميلة جات من إعلان: %s';

    public const SYSTEM_LINE_REF = 'العميلة جات من لينك: %s';

    public function __construct(private readonly OutboundService $outbound, private readonly ActivityLogger $logger) {}

    public function apply(Conversation $c, AdReferralData $referral, bool $opened): void
    {
        $context = ['source' => $referral->source, 'type' => $referral->type, 'ref' => $referral->ref, 'ad_id' => $referral->adId, 'ad_title' => $referral->adTitle, 'post_id' => $referral->postId];

        if ($c->ad_attributed_at !== null || (! $referral->isAd() && $referral->ref === null)) {
            $this->recordReferral($c, $referral, $this->logger->log(ActorType::System, null, ActivityLogger::CONVERSATION_REFERRAL, $c, $c, $context));

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

        $this->recordReferral($c, $referral, $this->logger->log(ActorType::System, null, ActivityLogger::CONVERSATION_REFERRAL, $c, $c, $context));

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

    /**
     * One history row per ad referral; a repeat of the same (conversation, ad, second) is ignored by the unique key.
     * Runs in a savepoint and is reported on failure: the history never breaks the inbox.
     */
    private function recordReferral(Conversation $c, AdReferralData $referral, ActivityLog $log): void
    {
        if ($referral->adId === null) {
            return;
        }

        try {
            DB::transaction(fn () => DB::table('conversation_ad_referrals')->insertOrIgnore([
                'conversation_id' => $c->id,
                'customer_id' => $c->customer_id,
                'ad_external_id' => mb_substr($referral->adId, 0, 64),
                'referred_at' => ($log->created_at ?? now())->format('Y-m-d H:i:s'),
            ]));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
