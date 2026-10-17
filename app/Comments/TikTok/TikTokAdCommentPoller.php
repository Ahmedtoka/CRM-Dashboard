<?php

namespace App\Comments\TikTok;

use App\Ads\Control\AdWriteService;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\MissingPermission;
use App\Ads\Platforms\RateLimited;
use App\Ads\Platforms\SecretScrubber;
use App\Ads\Platforms\TikTok\TikTokApi;
use App\Channels\Data\InboundCommentData;
use App\Comments\CommentIngestor;
use App\Enums\CommentStatus;
use App\Enums\Platform;
use App\Events\CommentUpdated;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdSet;
use App\Models\ChannelAccount;
use App\Models\Comment;
use App\Models\Post;
use App\Support\SafeBroadcast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * TikTok ad comments by polling (E1): the Marketing API has no comment webhook, and /comment/list/ filters by ad group
 * and by whole days only. One run per ad account reads, for each active ad group (status ENABLE/ACTIVE under an active
 * campaign, up to max_adgroups_per_run, least recently polled first), the comments of today and yesterday in the
 * account's timezone, and feeds the new ones to the CommentIngestor as platform tiktok with the ad attached. Already
 * known comments only follow a hide/unhide made in TikTok; our own replies (public_reply_external_id) are skipped.
 *
 * Rate limits: a throttle stops the run and parks the account for the wait TikTok asks for (≥ 5 minutes, A8); a missing
 * permission (the comment scope not granted) stops it with a warning. The comment bot runs only on comments younger
 * than bot_max_age_minutes, so the first poll does not answer two days of history.
 */
class TikTokAdCommentPoller
{
    public const CHANNEL_SOURCE = 'tiktok_ads';

    public function __construct(
        private readonly TikTokAdCommentsApi $api,
        private readonly CommentIngestor $ingestor,
    ) {}

    public static function cooldownKey(AdAccount $a): string
    {
        return 'tiktok-ad-comments:cooldown:'.$a->id;
    }

    /** @return array{adgroups: int, ingested: int, updated: int, skipped: ?string} */
    public function poll(AdAccount $a): array
    {
        $result = ['adgroups' => 0, 'ingested' => 0, 'updated' => 0, 'skipped' => null];
        if (Cache::has(self::cooldownKey($a))) {
            return ['skipped' => 'cooldown'] + $result;
        }
        // Any TikTok throttle (a sync, a Stop, another poll) parks the poller: Stops and syncs share the same quota.
        if (TikTokApi::throttledUntil() !== null) {
            return ['skipped' => 'throttled'] + $result;
        }
        $channel = $this->channelFor($a);
        if ($channel->status === 'disconnected') {
            return ['skipped' => 'channel_disconnected'] + $result;
        }

        $tz = $a->timezone ?: 'Africa/Cairo';
        try {
            $today = CarbonImmutable::now($tz);
        } catch (Throwable) {
            $today = CarbonImmutable::now('Africa/Cairo');
        }
        [$from, $to] = [$today->subDay()->toDateString(), $today->toDateString()];

        foreach ($this->adGroups($a) as $adgroupId) {
            // Marked before the call, so an ad group that keeps failing goes to the back of the rotation.
            Cache::put($this->polledKey($a, $adgroupId), now()->getTimestamp(), now()->addDays(2));
            try {
                $rows = $this->api->list($a, $adgroupId, $from, $to);
            } catch (RateLimited $e) {
                Cache::put(self::cooldownKey($a), true, now()->addSeconds(max(TikTokApi::THROTTLE_WAIT_SECONDS, (int) $e->retryAfterSeconds)));

                return ['skipped' => 'rate_limited'] + $result;
            } catch (MissingPermission $e) {
                Log::warning('tiktok.ad_comments.permission', ['ad_account_id' => $a->id, 'error' => SecretScrubber::scrub($e->getMessage())]);

                return ['skipped' => 'missing_permission'] + $result;
            } catch (AdsApiException $e) {
                // A dead token or an unknown error would fail for every ad group: one call and one log line per run.
                Log::warning('tiktok.ad_comments.list_failed', ['ad_account_id' => $a->id, 'adgroup_id' => $adgroupId, 'error' => SecretScrubber::scrub($e->getMessage())]);

                return ['skipped' => 'error'] + $result;
            }
            $result['adgroups']++;

            foreach ($rows as $row) {
                $outcome = $this->take($a, $channel, $row);
                if ($outcome === 'ingested') {
                    $result['ingested']++;
                } elseif ($outcome === 'updated') {
                    $result['updated']++;
                }
            }
        }

        return $result;
    }

    /** The TikTok channel the ad account's comments land on (one per advertiser, made on first use). */
    public function channelFor(AdAccount $a): ChannelAccount
    {
        return ChannelAccount::firstOrCreate(
            ['platform' => Platform::TikTok, 'external_id' => (string) $a->external_id],
            [
                'name' => 'TikTok · '.($a->name ?: $a->external_id),
                'driver' => 'live',
                'status' => 'connected',
                'connected_at' => now(),
                'profile' => ['source' => self::CHANNEL_SOURCE, 'ad_account_id' => $a->id],
            ],
        );
    }

    /** @return list<string> active ad group ids, least recently polled first, capped */
    private function adGroups(AdAccount $a): array
    {
        $ids = AdSet::query()
            ->join('ad_campaigns', 'ad_campaigns.id', '=', 'ad_sets.ad_campaign_id')
            ->where('ad_campaigns.ad_account_id', $a->id)
            ->whereIn('ad_sets.status', AdWriteService::ACTIVE_STATUSES)
            ->whereIn('ad_campaigns.status', AdWriteService::ACTIVE_STATUSES)
            ->orderBy('ad_sets.id')
            ->pluck('ad_sets.external_id')
            ->map(fn ($id) => (string) $id)
            ->filter(fn (string $id) => ctype_digit($id))
            ->unique()->values()->all();

        usort($ids, fn (string $x, string $y) => ((int) Cache::get($this->polledKey($a, $x), 0)) <=> ((int) Cache::get($this->polledKey($a, $y), 0)));

        return array_slice($ids, 0, max(1, (int) config('crm.tiktok_ad_comments.max_adgroups_per_run', 40)));
    }

    private function polledKey(AdAccount $a, string $adgroupId): string
    {
        return "tiktok-ad-comments:polled:{$a->id}:{$adgroupId}";
    }

    /** @return 'ingested'|'updated'|null */
    private function take(AdAccount $a, ChannelAccount $channel, array $row): ?string
    {
        $id = (string) ($row['comment_id'] ?? '');
        if ($id === '') {
            return null;
        }
        $onTikTok = fn ($q) => $q->where('platform', Platform::TikTok);
        $hidden = strtoupper((string) ($row['comment_status'] ?? '')) === 'HIDDEN';

        $known = Comment::where('external_id', $id)->whereHas('post', $onTikTok)->first();
        if ($known !== null) {
            // Only a status TikTok actually sent is followed: a missing field must not undo a hide made in the CRM.
            return array_key_exists('comment_status', $row) && $this->followVisibility($known, $hidden) ? 'updated' : null;
        }

        $post = $this->post($a, $channel, $row);
        if ($this->isOwnReply($post, $id, $row)) {
            return null;
        }

        $occurred = $this->time($row['create_time'] ?? null);
        $customerId = (string) ($row['user_id'] ?? '');
        $isReply = strtoupper((string) ($row['comment_type'] ?? '')) === 'REPLY';
        $maxAge = (int) config('crm.tiktok_ad_comments.bot_max_age_minutes', 120);

        $comment = $this->ingestor->ingest(new InboundCommentData(
            platform: Platform::TikTok,
            channelExternalId: (string) $channel->external_id,
            postExternalId: (string) $post->external_id,
            commentExternalId: $id,
            customerExternalId: $customerId !== '' ? $customerId : 'tiktok-anon-'.$id,
            customerName: trim((string) ($row['user_name'] ?? '')) ?: 'TikTok',
            body: (string) ($row['content'] ?? ''),
            occurredAt: $occurred ?? CarbonImmutable::now(),
            parentExternalId: $isReply && ! empty($row['original_comment_id']) ? (string) $row['original_comment_id'] : null,
            isAd: true,
            adId: $post->ad_id,
            adTitle: $post->ad_title,
        ), runBot: ! $hidden && $occurred !== null && $occurred->gt(now()->subMinutes($maxAge)) && $occurred->lte(now()->addMinutes(5)));
        if ($comment === null) {
            return null;
        }
        if ($hidden) {
            $comment->forceFill(['status' => CommentStatus::Hidden])->save();
        }

        return 'ingested';
    }

    /**
     * Our own reply listed back: its id is one the CRM posted (every reply id is kept on the post, and the latest on the
     * comment), or it is a thread reply whose text is the public reply the CRM posted under that parent (TikTok named
     * no id for it).
     */
    private function isOwnReply(Post $post, string $id, array $row): bool
    {
        if (in_array($id, array_map('strval', (array) ($post->platform_meta['own_reply_ids'] ?? [])), true)
            || Comment::where('public_reply_external_id', $id)->whereHas('post', fn ($q) => $q->where('platform', Platform::TikTok))->exists()) {
            return true;
        }
        $parent = (string) ($row['original_comment_id'] ?? '');
        if (strtoupper((string) ($row['comment_type'] ?? '')) !== 'REPLY' || $parent === '') {
            return false;
        }
        $parentReply = Comment::where('external_id', $parent)->where('post_id', $post->id)->value('public_reply');

        return $parentReply !== null && trim((string) $parentReply) === trim((string) ($row['content'] ?? ''));
    }

    /** A hide or unhide made in TikTok itself; true when our row changed. */
    private function followVisibility(Comment $c, bool $hidden): bool
    {
        $isHidden = $c->status === CommentStatus::Hidden;
        if ($hidden === $isHidden) {
            return false;
        }
        // TikTok's list may lag a hide just made in the CRM: a PUBLIC right after it is not an unhide.
        if (! $hidden && $c->updated_at !== null && $c->updated_at->gt(now()->subMinutes(10))) {
            return false;
        }
        $c->forceFill(['status' => $hidden ? CommentStatus::Hidden : ($c->public_replied_at !== null ? CommentStatus::Replied : CommentStatus::New)])->save();
        SafeBroadcast::send(new CommentUpdated($c));

        return true;
    }

    /** One post per ad (the ad's video can serve several ads; a reply needs that ad's own id and identity). */
    private function post(AdAccount $a, ChannelAccount $channel, array $row): Post
    {
        $adId = isset($row['ad_id']) && (string) $row['ad_id'] !== '' ? (string) $row['ad_id'] : null;
        $item = isset($row['tiktok_item_id']) && (string) $row['tiktok_item_id'] !== '' ? (string) $row['tiktok_item_id'] : null;
        $key = $adId !== null ? 'ad-'.$adId : 'item-'.($item ?? 'unknown');
        $ad = $adId !== null ? Ad::query()->with(['adSet', 'campaign'])->where('ad_account_id', $a->id)->where('external_id', $adId)->first() : null;

        $post = Post::firstOrCreate(['platform' => Platform::TikTok, 'external_id' => $key], [
            'channel_account_id' => $channel->id,
            'caption' => $ad?->body,
            'thumbnail_url' => $ad?->thumbnail_url,
            'is_ad' => true,
            'ad_id' => $adId,
            'ad_title' => $ad?->name,
            'ad_name' => $ad?->name,
            'ad_adset_name' => $ad?->adSet?->name,
            'ad_campaign_name' => $ad?->campaign?->name,
            'ad_checked_at' => now(),
        ]);

        $meta = array_filter([
            'advertiser_id' => (string) $a->external_id,
            'ad_account_id' => $a->id,
            'ad_id' => $adId,
            'adgroup_id' => isset($row['adgroup_id']) ? (string) $row['adgroup_id'] : null,
            'campaign_id' => isset($row['campaign_id']) ? (string) $row['campaign_id'] : null,
            'tiktok_item_id' => $item,
            'identity_id' => isset($row['identity_id']) ? (string) $row['identity_id'] : null,
            'identity_type' => isset($row['identity_type']) ? (string) $row['identity_type'] : null,
        ], fn ($v) => $v !== null && $v !== '');
        $merged = array_merge($post->platform_meta ?? [], $meta);
        if ($merged !== ($post->platform_meta ?? [])) {
            $post->forceFill(['platform_meta' => $merged])->save();
        }

        return $post;
    }

    /** TikTok's create_time: a 'Y-m-d H:i:s' UTC+0 string or a unix timestamp (ASSUMPTION: UTC); null when unreadable. */
    private function time(mixed $v): ?CarbonImmutable
    {
        try {
            if (is_numeric($v)) {
                return CarbonImmutable::createFromTimestampUTC((int) $v);
            }
            if (is_string($v) && trim($v) !== '') {
                return CarbonImmutable::parse($v, 'UTC')->utc();
            }
        } catch (Throwable) {
            // fall through
        }

        return null;
    }
}
