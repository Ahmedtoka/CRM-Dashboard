<?php

namespace App\Ads\Platforms\Meta;

use App\Ads\Platforms\AdPlatformDriver;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\Data\AccountDailyTotal;
use App\Ads\Platforms\Data\AccountInfo;
use App\Ads\Platforms\Data\AdRow;
use App\Ads\Platforms\Data\CreativeMedia;
use App\Ads\Platforms\Data\DailyAdMetric;
use App\Ads\Platforms\PreviewMarkup;
use App\Ads\Platforms\RateLimited;
use App\Ads\Platforms\TokenInvalid;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;

class MetaAdsDriver implements AdPlatformDriver
{
    private const PURCHASE_TYPES = ['purchase', 'omni_purchase', 'offsite_conversion.fb_pixel_purchase'];

    private const MESSAGING_TYPE = 'onsite_conversion.messaging_conversation_started_7d';

    /**
     * Every ad status, ARCHIVED and DELETED included: without this filter Meta silently leaves archived and
     * deleted ads out of a level=ad insights pull, so their spend would be missing (F-001).
     */
    public const INSIGHTS_STATUSES = ['ACTIVE', 'PAUSED', 'DELETED', 'PENDING_REVIEW', 'DISAPPROVED', 'PREAPPROVED', 'PENDING_BILLING_INFO', 'CAMPAIGN_PAUSED', 'ARCHIVED', 'ADSET_PAUSED', 'IN_PROCESS', 'WITH_ISSUES'];

    /** Campaign-level effective_status values: CAMPAIGN_PAUSED / ADSET_PAUSED exist only for ads and ad sets (Meta answers "Invalid parameter"). */
    public const CAMPAIGN_STATUSES = ['ACTIVE', 'PAUSED', 'DELETED', 'PENDING_REVIEW', 'DISAPPROVED', 'PREAPPROVED', 'PENDING_BILLING_INFO', 'ARCHIVED', 'IN_PROCESS', 'WITH_ISSUES'];

    /** @var list<string> run warnings (a list cut short by high usage), drained by the sync */
    private array $warnings = [];

    public function __construct(private readonly MetaAdsApi $api) {}

    public function admit(AdAccount $a): void
    {
        $this->api->admit($this->actId($a));
    }

    public function drainWarnings(): array
    {
        $out = $this->warnings;
        $this->warnings = [];

        return $out;
    }

    /**
     * paginate() plus a run warning when Meta's usage header stopped the paging early (the rows read are kept).
     *
     * @return list<array<string, mixed>>
     */
    private function pages(string $label, string $token, string $path, array $query): array
    {
        $rows = $this->api->paginate($token, $path, $query);
        if ($this->api->stoppedAt() !== null) {
            $this->warnings[] = $this->stoppedMessage($label, count($rows));
        }

        return $rows;
    }

    private function stoppedMessage(string $label, int $rows): string
    {
        return sprintf('%s: stopped paging at %d%% usage (%d rows kept)', $label, (int) $this->api->stoppedAt(), $rows);
    }

    public function accounts(AdPlatformConnection $c): array
    {
        $rows = $this->pages('Ad accounts', $this->token($c), 'me/adaccounts', [
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

    /** The statuses AD_STATUSES leaves out: the sweep lists them so both lists together cover INSIGHTS_STATUSES. */
    public const SWEEP_AD_STATUSES = ['ARCHIVED', 'DELETED', 'PREAPPROVED', 'PENDING_BILLING_INFO'];

    public function ads(AdAccount $a): array
    {
        $rows = $this->pages('Ad list', $this->token($a->connection), $this->actId($a).'/ads', [
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
        $rows = $this->pages('Ad metrics', $this->token($a->connection), $this->actId($a).'/insights', [
            'level' => 'ad',
            'time_increment' => 1,
            'time_range' => json_encode(['since' => $from->toDateString(), 'until' => $to->toDateString()]),
            'filtering' => json_encode([['field' => 'ad.effective_status', 'operator' => 'IN', 'value' => self::INSIGHTS_STATUSES]]),
            'fields' => 'ad_id,ad_name,campaign_id,campaign_name,adset_id,adset_name,spend,impressions,clicks,inline_link_clicks,reach,actions,action_values',
            'limit' => 500,
        ] + $this->attributionParams());

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
                linkClicks: (int) ($r['inline_link_clicks'] ?? 0),
                msgConversations: (int) $this->actionValue($r['actions'] ?? [], self::MESSAGING_TYPE),
            );
        }

        return $out;
    }

    public function accountDaily(AdAccount $a, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        // level=account counts archived and deleted ads too, so no status filter is needed here.
        $rows = $this->api->paginate($this->token($a->connection), $this->actId($a).'/insights', [
            'level' => 'account',
            'time_increment' => 1,
            'time_range' => json_encode(['since' => $from->toDateString(), 'until' => $to->toDateString()]),
            'fields' => 'spend,impressions,actions,action_values,account_currency',
            'limit' => 500,
        ] + $this->attributionParams());
        if ($this->api->stoppedAt() !== null) {
            // A cut control would read as zero spend on the missing days and unlock deletes: no control this run.
            throw new AdsApiException(sprintf('stopped paging at %d%% usage', (int) $this->api->stoppedAt()));
        }

        $out = [];
        foreach ($rows as $r) {
            if (empty($r['date_start'])) {
                continue;
            }
            $out[] = new AccountDailyTotal(
                date: substr((string) $r['date_start'], 0, 10),
                spend: (float) ($r['spend'] ?? 0),
                impressions: (int) ($r['impressions'] ?? 0),
                purchases: $this->purchaseValue($r['actions'] ?? []),
                purchaseValue: $this->purchaseValue($r['action_values'] ?? []),
                currency: isset($r['account_currency']) ? (string) $r['account_currency'] : null,
            );
        }

        return $out;
    }

    public function statuses(AdAccount $a): array
    {
        $token = $this->token($a->connection);
        $out = ['ads' => null, 'campaigns' => null, 'warnings' => []];

        // Each list fails alone: a refused campaigns call must not throw away the ads list (and the reverse).
        // One call per status: Meta refuses the whole call ("Invalid parameter") when it rejects one value, so a status
        // it no longer accepts on /ads costs only that status. Any refused or cut status makes the list incomplete:
        // its ads still get their status, but nothing may be judged GONE from it (ads_complete false).
        $out['ads'] = [];
        $out['ads_complete'] = true;
        foreach (self::SWEEP_AD_STATUSES as $status) {
            try {
                foreach ($this->api->paginate($token, $this->actId($a).'/ads', [
                    'fields' => 'id,status,effective_status',
                    'effective_status' => json_encode([$status]),
                    'limit' => 500,
                ]) as $r) {
                    if (! empty($r['id'])) {
                        $out['ads'][(string) $r['id']] = ['status' => $r['status'] ?? null, 'effective_status' => $r['effective_status'] ?? null];
                    }
                }
                if ($this->api->stoppedAt() !== null) {
                    $out['ads_complete'] = false;
                    $out['warnings'][] = "ads list {$status} ".$this->stoppedMessage('incomplete', count($out['ads']));
                }
            } catch (TokenInvalid|RateLimited $e) {
                throw $e;
            } catch (AdsApiException $e) {
                $out['ads_complete'] = false;
                $out['warnings'][] = "ads list {$status}: ".$e->getMessage();
            }
        }

        try {
            $out['campaigns'] = [];
            foreach ($this->api->paginate($token, $this->actId($a).'/campaigns', [
                'fields' => 'id,name,status,effective_status,objective',
                'effective_status' => json_encode(self::CAMPAIGN_STATUSES),
                'limit' => 500,
            ]) as $r) {
                if (! empty($r['id'])) {
                    $out['campaigns'][(string) $r['id']] = [
                        'name' => $r['name'] ?? null, 'status' => $r['status'] ?? null,
                        'effective_status' => $r['effective_status'] ?? null, 'objective' => $r['objective'] ?? null,
                    ];
                }
            }
            if ($this->api->stoppedAt() !== null) {
                // Campaigns are updated one by one: the part read is still right.
                $out['warnings'][] = 'campaigns list '.$this->stoppedMessage('partial', count($out['campaigns']));
            }
        } catch (TokenInvalid|RateLimited $e) {
            throw $e;
        } catch (AdsApiException $e) {
            $out['campaigns'] = null;
            $out['warnings'][] = 'campaigns list: '.$e->getMessage();
        }

        return $out;
    }

    /** One light paged call: id and the two statuses of every campaign, archived and deleted included. */
    public function campaignStatuses(AdAccount $a): ?array
    {
        $out = [];
        foreach ($this->pages('Campaign statuses', $this->token($a->connection), $this->actId($a).'/campaigns', [
            'fields' => 'id,status,effective_status',
            'effective_status' => json_encode(self::CAMPAIGN_STATUSES),
            'limit' => 500,
        ]) as $r) {
            if (! empty($r['id'])) {
                $out[(string) $r['id']] = ['status' => $r['status'] ?? null, 'effective_status' => $r['effective_status'] ?? null];
            }
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
        try {
            $token = $c->credentials['access_token'] ?? null;
        } catch (DecryptException) {
            // APP_KEY changed or the column was damaged: the page that fixes it must still open (F-034).
            throw new AdsApiException('credentials unreadable, re-enter the token');
        }
        if (! $token) {
            throw new AdsApiException('Meta access token is missing.');
        }

        return (string) $token;
    }

    private function actId(AdAccount $a): string
    {
        return str_starts_with($a->external_id, 'act_') ? $a->external_id : 'act_'.$a->external_id;
    }

    /**
     * crm.ads.meta.attribution as query params, sent on every insights call so the attribution is explicit (R-07).
     *
     * @return array<string, string>
     */
    private function attributionParams(): array
    {
        $out = [];
        foreach ((array) config('crm.ads.meta.attribution', []) as $key => $value) {
            $out[(string) $key] = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_array($value) => (string) json_encode(array_values($value)),
                default => (string) $value,
            };
        }

        return $out;
    }

    private function actionValue(array $actions, string $type): float
    {
        foreach ($actions as $row) {
            if (($row['action_type'] ?? null) === $type && isset($row['value'])) {
                return (float) $row['value'];
            }
        }

        return 0.0;
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
