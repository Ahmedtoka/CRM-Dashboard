<?php

namespace App\Ads\Platforms\Meta;

use App\Ads\Platforms\AdPlatformDriver;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\Data\AccountInfo;
use App\Ads\Platforms\Data\AdRow;
use App\Ads\Platforms\Data\CreativeMedia;
use App\Ads\Platforms\Data\DailyAdMetric;
use App\Ads\Platforms\PreviewMarkup;
use App\Ads\Platforms\TokenInvalid;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use Carbon\CarbonImmutable;

class MetaAdsDriver implements AdPlatformDriver
{
    private const PURCHASE_TYPES = ['purchase', 'omni_purchase', 'offsite_conversion.fb_pixel_purchase'];

    public function __construct(private readonly MetaAdsApi $api) {}

    public function accounts(AdPlatformConnection $c): array
    {
        $rows = $this->api->paginate($this->token($c), 'me/adaccounts', [
            'fields' => 'id,name,currency,timezone_name,account_status,balance',
            'limit' => 200,
        ]);

        return array_values(array_map(fn (array $r) => new AccountInfo(
            externalId: (string) $r['id'],
            name: (string) ($r['name'] ?? $r['id']),
            currency: (string) ($r['currency'] ?? 'EGP'),
            timezone: $r['timezone_name'] ?? null,
            status: match ((int) ($r['account_status'] ?? 0)) {
                1 => 'active',
                2 => 'disabled',
                default => (string) ($r['account_status'] ?? 'unknown'),
            },
            balance: isset($r['balance']) ? round(((float) $r['balance']) / 100, 2) : null,
        ), $rows));
    }

    /**
     * Every ad that can still spend or has just spent: paused by its campaign/ad set, in review or with
     * issues included, so spend leaders keep their creative. Archived/deleted ads stay out.
     */
    public const AD_STATUSES = ['ACTIVE', 'PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED', 'DISAPPROVED', 'WITH_ISSUES', 'PENDING_REVIEW', 'IN_PROCESS'];

    public function ads(AdAccount $a): array
    {
        $rows = $this->api->paginate($this->token($a->connection), $this->actId($a).'/ads', [
            'effective_status' => json_encode(self::AD_STATUSES),
            'fields' => 'id,name,status,effective_status,created_time,'
                .'creative{id,name,thumbnail_url,image_url,video_id,title,body,instagram_permalink_url,url_tags,object_story_id,effective_object_story_id,object_story_spec,asset_feed_spec},'
                .'campaign{id,name,status,objective},adset{id,name,status,is_dynamic_creative}',
            // Full creative specs are heavy: 200 per page makes Meta refuse big accounts outright.
            'limit' => 50,
        ]);

        return array_values(array_map(fn (array $r) => $this->mapAd($r), $rows));
    }

    public function dailyMetrics(AdAccount $a, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->api->paginate($this->token($a->connection), $this->actId($a).'/insights', [
            'level' => 'ad',
            'time_increment' => 1,
            'time_range' => json_encode(['since' => $from->toDateString(), 'until' => $to->toDateString()]),
            'fields' => 'ad_id,ad_name,campaign_id,campaign_name,adset_id,adset_name,spend,impressions,clicks,reach,actions,action_values',
            'limit' => 500,
        ]);

        $out = [];
        foreach ($rows as $r) {
            if (empty($r['ad_id'])) {
                continue;
            }
            $out[] = new DailyAdMetric(
                adExternalId: (string) $r['ad_id'],
                date: (string) ($r['date_start'] ?? $from->toDateString()),
                spend: (float) ($r['spend'] ?? 0),
                impressions: (int) ($r['impressions'] ?? 0),
                clicks: (int) ($r['clicks'] ?? 0),
                reach: (int) ($r['reach'] ?? 0),
                purchases: $this->purchaseValue($r['actions'] ?? []),
                purchaseValue: $this->purchaseValue($r['action_values'] ?? []),
                adName: $r['ad_name'] ?? null,
                campaignId: $r['campaign_id'] ?? null,
                campaignName: $r['campaign_name'] ?? null,
                adSetId: $r['adset_id'] ?? null,
                adSetName: $r['adset_name'] ?? null,
            );
        }

        return $out;
    }

    public function creativeMedia(AdAccount $a, array $adExternalIds): array
    {
        $token = $this->token($a->connection);
        $adExternalIds = array_values(array_unique($adExternalIds));
        $stored = Ad::query()->where('ad_account_id', $a->id)->whereIn('external_id', $adExternalIds)
            ->get()->keyBy('external_id');

        $previews = $this->api->batch($token, array_map(
            fn ($id) => "{$id}/previews?ad_format=DESKTOP_FEED_STANDARD", $adExternalIds));

        // Video / story lookups only for ads we already know, deduped.
        $videoOf = [];
        $storyOf = [];
        foreach ($adExternalIds as $id) {
            $ad = $stored->get($id);
            $video = $ad?->raw['creative']['video_id'] ?? $ad?->raw['creative']['object_story_spec']['video_data']['video_id'] ?? null;
            $story = $ad?->object_story_id;
            if ($video) {
                $videoOf[$id] = (string) $video;
            }
            if ($story && str_contains((string) $story, '_')) {
                $storyOf[$id] = (string) $story;
            }
        }
        $videoIds = array_values(array_unique($videoOf));
        $storyIds = array_values(array_unique($storyOf));
        $videos = $this->api->batch($token, array_map(fn ($v) => "{$v}?fields=source,picture", $videoIds));
        $stories = $this->api->batch($token, array_map(fn ($s) => "{$s}?fields=full_picture,permalink_url", $storyIds));

        $out = [];
        foreach ($adExternalIds as $i => $id) {
            $pv = $previews[$i] ?? null;
            $html = $pv && $pv['code'] === 200 ? ($pv['body']['data'][0]['body'] ?? null) : null;
            // Untrusted markup: keep only a host-checked iframe src and a rebuilt single iframe.
            $previewUrl = PreviewMarkup::iframeSrc($html);
            $html = PreviewMarkup::singleIframe($html);

            $video = isset($videoOf[$id]) ? ($videos[array_search($videoOf[$id], $videoIds, true)] ?? null) : null;
            $videoBody = $video && $video['code'] === 200 ? $video['body'] : [];
            $story = isset($storyOf[$id]) ? ($stories[array_search($storyOf[$id], $storyIds, true)] ?? null) : null;
            $storyBody = $story && $story['code'] === 200 ? $story['body'] : [];

            $out[] = new CreativeMedia(
                adExternalId: $id,
                imageUrl: $storyBody['full_picture'] ?? null,
                videoUrl: $videoBody['source'] ?? null,
                thumbnailUrl: $videoBody['picture'] ?? $storyBody['full_picture'] ?? null,
                previewUrl: $previewUrl,
                previewHtml: $html,
                permalinkUrl: $storyBody['permalink_url'] ?? null,
            );
        }

        return $out;
    }

    public function test(AdPlatformConnection $c): ?string
    {
        try {
            $this->api->get($this->token($c), 'me', ['fields' => 'id,name']);

            return null;
        } catch (TokenInvalid $e) {
            throw $e; // the caller marks the connection needs_reconnect
        } catch (AdsApiException $e) {
            return $e->getMessage();
        }
    }

    private function token(AdPlatformConnection $c): string
    {
        $token = $c->credentials['access_token'] ?? null;
        if (! $token) {
            throw new AdsApiException('Meta access token is missing.');
        }

        return (string) $token;
    }

    private function actId(AdAccount $a): string
    {
        return str_starts_with($a->external_id, 'act_') ? $a->external_id : 'act_'.$a->external_id;
    }

    /** First present of the purchase action types (Arena's priority). */
    private function purchaseValue(array $actions): float
    {
        $by = [];
        foreach ($actions as $row) {
            if (isset($row['action_type'], $row['value'])) {
                $by[$row['action_type']] = $row['value'];
            }
        }
        foreach (self::PURCHASE_TYPES as $type) {
            if (isset($by[$type])) {
                return (float) $by[$type];
            }
        }

        return 0.0;
    }

    private function mapAd(array $r): AdRow
    {
        $creative = $r['creative'] ?? [];
        $spec = $creative['object_story_spec'] ?? [];
        $linkData = $spec['link_data'] ?? [];
        $asset = $creative['asset_feed_spec'] ?? [];
        $adset = $r['adset'] ?? [];
        $campaign = $r['campaign'] ?? [];

        $videoId = $creative['video_id'] ?? $spec['video_data']['video_id'] ?? $asset['videos'][0]['video_id'] ?? null;
        $children = $linkData['child_attachments'] ?? [];

        if (! empty($creative['video_id']) || ! empty($spec['video_data'])) {
            $type = 'video';
        } elseif (! empty($children)) {
            $type = 'carousel';
        } elseif (! empty($asset) || ! empty($adset['is_dynamic_creative'])) {
            $type = 'dynamic';
        } elseif ($videoId) {
            $type = 'video';
        } else {
            $type = 'image';
        }

        $carousel = null;
        if ($type === 'carousel') {
            $carousel = array_values(array_map(fn ($c) => [
                'image_url' => $c['picture'] ?? $c['image_url'] ?? null,
                'link' => $c['link'] ?? null,
                'name' => $c['name'] ?? null,
            ], $children));
        }

        $image = $creative['image_url'] ?? $asset['images'][0]['url'] ?? $linkData['picture'] ?? $spec['photo_data']['url'] ?? $spec['video_data']['image_url'] ?? null;
        $story = $creative['effective_object_story_id'] ?? $creative['object_story_id'] ?? null;

        return new AdRow(
            externalId: (string) $r['id'],
            name: (string) ($r['name'] ?? $r['id']),
            status: $r['status'] ?? null,
            effectiveStatus: $r['effective_status'] ?? null,
            campaignId: $campaign['id'] ?? null,
            campaignName: $campaign['name'] ?? null,
            campaignStatus: $campaign['status'] ?? null,
            objective: $campaign['objective'] ?? null,
            adSetId: $adset['id'] ?? null,
            adSetName: $adset['name'] ?? null,
            adSetStatus: $adset['status'] ?? null,
            type: $type,
            headline: $creative['title'] ?? $linkData['name'] ?? $spec['video_data']['title'] ?? null,
            body: $creative['body'] ?? $linkData['message'] ?? $spec['video_data']['message'] ?? null,
            thumbnailUrl: $creative['thumbnail_url'] ?? $image,
            imageUrl: $image,
            videoId: $videoId ? (string) $videoId : null,
            objectStoryId: $story ? (string) $story : null,
            instagramPermalinkUrl: $creative['instagram_permalink_url'] ?? null,
            urlTags: $creative['url_tags'] ?? null,
            carousel: $carousel,
            createdTime: $r['created_time'] ?? null,
            raw: $r,
        );
    }
}
