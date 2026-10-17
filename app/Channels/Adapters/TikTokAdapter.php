<?php

namespace App\Channels\Adapters;

use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\MissingPermission;
use App\Ads\Platforms\RateLimited;
use App\Ads\Platforms\SecretScrubber;
use App\Channels\Contracts\ChannelAdapter;
use App\Channels\Contracts\UnhidesComments;
use App\Channels\Data\ChannelCapabilities;
use App\Channels\Data\SendResult;
use App\Comments\TikTok\TikTokAdCommentsApi;
use App\Enums\Platform;
use App\Models\AdAccount;
use App\Models\ChannelAccount;
use App\Models\Comment;
use App\Models\CustomerIdentity;
use App\Models\MessageAttachment;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TikTok, Phase 1 (E1): ad comments only, through the Marketing API on the ad account's token. A reply goes under a
 * first-level comment as the ad's identity (the ad, video item and identity come from the post's platform_meta, written
 * by the poller); hide / unhide set the comment HIDDEN / PUBLIC. There is no comment webhook (the poller reads them), no
 * private reply (Comment-to-Message is not offered in Egypt) and no DMs until TikTok approves Business Messaging (E2).
 */
final class TikTokAdapter implements ChannelAdapter, UnhidesComments
{
    public const PRIVATE_REPLY_REFUSED = 'TikTok does not allow a private message from a comment in Egypt: reply publicly; the customer can message the account themselves.';

    public const DMS_NOT_CONNECTED = 'TikTok messages are not connected yet (waiting for TikTok approval).';

    public const TOP_LEVEL_ONLY = 'TikTok allows replies to first-level comments only; reply to the original comment instead.';

    public function __construct(private readonly TikTokAdCommentsApi $api) {}

    public function platform(): Platform
    {
        return Platform::TikTok;
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(privateReply: false, hideComment: true, windowHours: 48, humanAgentHours: 0, templatesOutsideWindow: false);
    }

    public function handshake(Request $request): ?Response
    {
        return null;
    }

    /** No TikTok webhook is connected in Phase 1: every request is refused. */
    public function verifySignature(Request $request): bool
    {
        return false;
    }

    public function normalize(array $payload): array
    {
        return [];
    }

    public function sendText(ChannelAccount $account, CustomerIdentity $to, string $text, array $options = []): SendResult
    {
        return SendResult::fail(self::DMS_NOT_CONNECTED);
    }

    public function sendAttachment(ChannelAccount $account, CustomerIdentity $to, MessageAttachment $attachment, ?string $caption = null, array $options = []): SendResult
    {
        return SendResult::fail(self::DMS_NOT_CONNECTED);
    }

    public function replyToComment(ChannelAccount $account, string $commentExternalId, string $text): SendResult
    {
        return $this->call($commentExternalId, function (Comment $c, AdAccount $a, array $meta) use ($text) {
            if ($c->parent_external_id !== null) {
                return SendResult::fail(self::TOP_LEVEL_ONLY);
            }

            return SendResult::ok($this->api->reply($a, $meta, (string) $c->external_id, $text));
        });
    }

    public function hideComment(ChannelAccount $account, string $commentExternalId): SendResult
    {
        return $this->visibility($commentExternalId, true);
    }

    public function unhideComment(ChannelAccount $account, string $commentExternalId): SendResult
    {
        return $this->visibility($commentExternalId, false);
    }

    public function sendPrivateReply(ChannelAccount $account, string $commentExternalId, string $text): SendResult
    {
        return SendResult::fail(self::PRIVATE_REPLY_REFUSED);
    }

    public function typing(ChannelAccount $account, CustomerIdentity $to, bool $on): void {}

    private function visibility(string $commentExternalId, bool $hidden): SendResult
    {
        return $this->call($commentExternalId, function (Comment $c, AdAccount $a) use ($hidden) {
            $this->api->setHidden($a, (string) $c->external_id, $hidden);

            return SendResult::ok((string) $c->external_id);
        });
    }

    /**
     * The comment, its ad account and the post's platform_meta for one call; platform errors become a failed result
     * with TikTok's own text (scrubbed). A throttle is retryable.
     *
     * @param  callable(Comment, AdAccount, array<string, mixed>): SendResult  $fn
     */
    private function call(string $commentExternalId, callable $fn): SendResult
    {
        $comment = Comment::with('post')->where('external_id', $commentExternalId)
            ->whereHas('post', fn ($q) => $q->where('platform', Platform::TikTok))->first();
        $meta = $comment?->post?->platform_meta ?? [];
        $account = null;
        if (! empty($meta['ad_account_id'])) {
            $account = AdAccount::with('connection')->where('platform', 'tiktok')->find((int) $meta['ad_account_id']);
        }
        if ($account === null && ! empty($meta['advertiser_id'])) {
            $account = AdAccount::with('connection')->where('platform', 'tiktok')->where('external_id', (string) $meta['advertiser_id'])->first();
        }
        if ($comment === null || $account === null) {
            return SendResult::fail('This TikTok comment is not linked to a TikTok ad account in the CRM.');
        }
        if (config('crm.ads.drivers.tiktok') !== 'live') {
            return SendResult::fail('TikTok is not live on this server.');
        }

        try {
            return $fn($comment, $account, $meta);
        } catch (RateLimited $e) {
            return SendResult::fail('TikTok asked to slow down; try again in a few minutes.', retryable: true);
        } catch (MissingPermission $e) {
            return SendResult::fail('TikTok refused (missing permission for ad comments): '.SecretScrubber::scrub($e->getMessage()));
        } catch (AdsApiException $e) {
            return SendResult::fail(SecretScrubber::scrub($e->getMessage()));
        }
    }
}
