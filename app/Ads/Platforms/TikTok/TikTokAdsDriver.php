<?php

namespace App\Ads\Platforms\TikTok;

use App\Ads\Platforms\AdPlatformDriver;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\Data\AccountInfo;
use App\Ads\Platforms\Data\AdRow;
use App\Ads\Platforms\Data\CreativeMedia;
use App\Ads\Platforms\Data\DailyAdMetric;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use Carbon\CarbonImmutable;

/** TikTok Business API v1.3. The token travels in the Access-Token header only. */
class TikTokAdsDriver implements AdPlatformDriver
{
    public function __construct(private readonly TikTokApi $api) {}

    public function accounts(AdPlatformConnection $c): array
    {
        $ids = $this->advertiserIds($c);
        if ($ids === []) {
            throw new AdsApiException('No TikTok advertiser ids are configured.');
        }

        $rows = $this->advertiserInfo($this->token($c), $ids);

        return array_values(array_map(fn (array $r) => new AccountInfo(
            externalId: (string) $r['advertiser_id'],
            name: (string) ($r['name'] ?? $r['advertiser_id']),
            currency: (string) ($r['currency'] ?? 'USD'),
            timezone: $r['timezone'] ?? null,
            status: match ((string) ($r['status'] ?? '')) {
                'STATUS_ENABLE' => 'active',
                'STATUS_DISABLE' => 'disabled',
                default => strtolower((string) ($r['status'] ?? 'unknown')),
            },
            balance: isset($r['balance']) ? round((float) $r['balance'], 2) : null,
        ), $rows));
    }

    public function ads(AdAccount $a): array
    {
        $rows = $this->paginate($this->token($a->connection), 'ad/get/', [
            'advertiser_id' => $a->external_id,
            'fields' => json_encode(['ad_id', 'ad_name', 'operation_status', 'secondary_status', 'campaign_id', 'campaign_name', 'adgroup_id', 'adgroup_name', 'ad_format', 'ad_text', 'video_id', 'image_ids', 'create_time', 'landing_page_url']),
        ]);

        return array_values(array_map(fn (array $r) => $this->mapAd($r), array_filter($rows, fn ($r) => ! empty($r['ad_id']))));
    }

    public function dailyMetrics(AdAccount $a, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->paginate($this->token($a->connection), 'report/integrated/get/', [
            'advertiser_id' => $a->external_id,
            'report_type' => 'BASIC',
            'data_level' => 'AUCTION_AD',
            'dimensions' => json_encode(['ad_id', 'stat_time_day']),
            'metrics' => json_encode(['spend', 'impressions', 'clicks', 'reach', 'complete_payment', 'total_complete_payment_rate', 'ad_name', 'campaign_id', 'campaign_name', 'adgroup_id', 'adgroup_name']),
            'start_date' => $from->toDateString(),
            'end_date' => $to->toDateString(),
        ]);

        $out = [];
        foreach ($rows as $r) {
            $dim = $r['dimensions'] ?? [];
            $m = $r['metrics'] ?? [];
            if (empty($dim['ad_id']) || empty($dim['stat_time_day'])) {
                continue;
            }
            $out[] = new DailyAdMetric(
                adExternalId: (string) $dim['ad_id'],
                date: substr((string) $dim['stat_time_day'], 0, 10),
                spend: (float) ($m['spend'] ?? 0),
                impressions: (int) ($m['impressions'] ?? 0),
                clicks: (int) ($m['clicks'] ?? 0),
                reach: (int) ($m['reach'] ?? 0),
                purchases: (float) ($m['complete_payment'] ?? 0),
                purchaseValue: (float) ($m['total_complete_payment_rate'] ?? 0),
                adName: $m['ad_name'] ?? null,
                campaignId: isset($m['campaign_id']) ? (string) $m['campaign_id'] : null,
                campaignName: $m['campaign_name'] ?? null,
                adSetId: isset($m['adgroup_id']) ? (string) $m['adgroup_id'] : null,
                adSetName: $m['adgroup_name'] ?? null,
            );
        }

        return $out;
    }

    public function creativeMedia(AdAccount $a, array $adExternalIds): array
    {
        $adExternalIds = array_values(array_unique($adExternalIds));
        $stored = Ad::query()->where('ad_account_id', $a->id)->whereIn('external_id', $adExternalIds)
            ->get()->keyBy('external_id');

        $videoOf = [];
        foreach ($adExternalIds as $id) {
            $video = $stored->get($id)?->raw['video_id'] ?? null;
            if ($video) {
                $videoOf[$id] = (string) $video;
            }
        }

        $token = $this->token($a->connection);
        $byVideo = [];
        foreach (array_chunk(array_values(array_unique($videoOf)), 60) as $chunk) {
            $data = $this->get($token, 'file/video/ad/info/', [
                'advertiser_id' => $a->external_id,
                'video_ids' => json_encode($chunk),
            ]);
            foreach ($data['list'] ?? [] as $v) {
                if (! empty($v['video_id'])) {
                    $byVideo[(string) $v['video_id']] = $v;
                }
            }
        }

        $out = [];
        foreach ($adExternalIds as $id) {
            $v = isset($videoOf[$id]) ? ($byVideo[$videoOf[$id]] ?? []) : [];
            $out[] = new CreativeMedia(
                adExternalId: $id,
                videoUrl: $v['preview_url'] ?? null,
                thumbnailUrl: $v['video_cover_url'] ?? null,
                previewUrl: $v['preview_url'] ?? null,
            );
        }

        return $out;
    }

    public function test(AdPlatformConnection $c): ?string
    {
        try {
            $ids = $this->advertiserIds($c);
            if ($ids === []) {
                return 'No TikTok advertiser ids are configured.';
            }
            $this->advertiserInfo($this->token($c), [$ids[0]]);

            return null;
        } catch (AdsApiException $e) {
            return $e->getMessage();
        }
    }

    /** @param  list<string>  $ids */
    private function advertiserInfo(string $token, array $ids): array
    {
        $rows = [];
        foreach (array_chunk(array_values($ids), 100) as $chunk) {
            $data = $this->get($token, 'advertiser/info/', [
                'advertiser_ids' => json_encode($chunk),
                'fields' => json_encode(['advertiser_id', 'name', 'currency', 'timezone', 'status', 'balance']),
            ]);
            $rows = array_merge($rows, $data['list'] ?? []);
        }

        return $rows;
    }

    /** @return list<string> */
    private function advertiserIds(AdPlatformConnection $c): array
    {
        $ids = $c->credentials['advertiser_ids'] ?? [];

        return array_values(array_filter(array_map('strval', (array) $ids)));
    }

    private function token(AdPlatformConnection $c): string
    {
        $token = $c->credentials['access_token'] ?? null;
        if (! $token) {
            throw new AdsApiException('TikTok access token is missing.');
        }

        return (string) $token;
    }

    /** @return array<string, mixed> the envelope data */
    private function get(string $token, string $path, array $query): array
    {
        return $this->api->get($token, $path, $query);
    }

    /** @return list<array<string, mixed>> merged data.list rows across pages */
    private function paginate(string $token, string $path, array $query): array
    {
        return $this->api->paginate($token, $path, $query);
    }

    private function mapAd(array $r): AdRow
    {
        $video = ! empty($r['video_id']) ? (string) $r['video_id'] : null;
        $created = $r['create_time'] ?? null;

        return new AdRow(
            externalId: (string) $r['ad_id'],
            name: (string) ($r['ad_name'] ?? $r['ad_id']),
            status: $r['operation_status'] ?? null,
            effectiveStatus: $r['secondary_status'] ?? null,
            campaignId: isset($r['campaign_id']) ? (string) $r['campaign_id'] : null,
            campaignName: $r['campaign_name'] ?? null,
            campaignStatus: null,
            objective: null,
            adSetId: isset($r['adgroup_id']) ? (string) $r['adgroup_id'] : null,
            adSetName: $r['adgroup_name'] ?? null,
            adSetStatus: null,
            type: $video ? 'video' : 'image',
            headline: null,
            body: $r['ad_text'] ?? null,
            thumbnailUrl: null,
            imageUrl: null,
            videoId: $video,
            objectStoryId: null,
            instagramPermalinkUrl: null,
            urlTags: null,
            carousel: null,
            createdTime: $created ? (string) $created : null,
            raw: $r,
        );
    }
}
