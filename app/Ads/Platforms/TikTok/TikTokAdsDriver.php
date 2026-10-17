<?php

namespace App\Ads\Platforms\TikTok;

use App\Ads\Platforms\AdPlatformDriver;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\Data\AccountDailyTotal;
use App\Ads\Platforms\Data\AccountInfo;
use App\Ads\Platforms\Data\AdRow;
use App\Ads\Platforms\Data\CreativeMedia;
use App\Ads\Platforms\Data\DailyAdMetric;
use App\Ads\Platforms\RateLimited;
use App\Ads\Platforms\TokenInvalid;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use Carbon\CarbonImmutable;

/** TikTok Business API v1.3. The token travels in the Access-Token header only. */
class TikTokAdsDriver implements AdPlatformDriver
{
    /** @var list<string> */
    private array $warnings = [];

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
            'fields' => json_encode(['ad_id', 'ad_name', 'operation_status', 'secondary_status', 'campaign_id', 'campaign_name', 'adgroup_id', 'adgroup_name', 'ad_format', 'ad_text', 'display_name', 'video_id', 'image_ids', 'create_time', 'landing_page_url']),
        ]);
        $rows = array_values(array_filter($rows, fn ($r) => ! empty($r['ad_id'])));

        // A4: ad/get carries no parent status or objective; one light read per parent level fills them (each failing
        // alone into a warning; a throttle or a dead token still stops the run).
        $token = $this->token($a->connection);
        $campaigns = $this->parentRows($token, $a, 'campaign/get/', 'campaign_ids', 'campaign_id',
            ['campaign_id', 'operation_status', 'secondary_status', 'objective_type'], array_column($rows, 'campaign_id'));
        $groups = $this->parentRows($token, $a, 'adgroup/get/', 'adgroup_ids', 'adgroup_id',
            ['adgroup_id', 'operation_status', 'secondary_status'], array_column($rows, 'adgroup_id'));

        return array_map(fn (array $r) => $this->mapAd($r, $campaigns[(string) ($r['campaign_id'] ?? '')] ?? [], $groups[(string) ($r['adgroup_id'] ?? '')] ?? []), $rows);
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
                // A9 OWNER CHECK: despite its name, total_complete_payment_rate is TikTok's «Total Purchase Value»
                // (formerly time_attr_total_shopping_value; third-party source). Compare one day with Ads Manager once.
                purchaseValue: (float) ($m['total_complete_payment_rate'] ?? 0),
                adName: $m['ad_name'] ?? null,
                campaignId: isset($m['campaign_id']) ? (string) $m['campaign_id'] : null,
                campaignName: $m['campaign_name'] ?? null,
                adSetId: isset($m['adgroup_id']) ? (string) $m['adgroup_id'] : null,
                adSetName: $m['adgroup_name'] ?? null,
                // TikTok's clicks are destination clicks; no extra field is requested for this.
                linkClicks: isset($m['clicks']) ? (int) $m['clicks'] : 0,
            );
        }

        return $out;
    }

    /**
     * The control total (A3): report/integrated/get at AUCTION_ADVERTISER level by day, which counts every ad status.
     * ASSUMPTION (owner check): the same purchase metrics as the ad level (see dailyMetrics).
     */
    public function accountDaily(AdAccount $a, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        $rows = $this->paginate($this->token($a->connection), 'report/integrated/get/', [
            'advertiser_id' => $a->external_id,
            'report_type' => 'BASIC',
            'data_level' => 'AUCTION_ADVERTISER',
            'dimensions' => json_encode(['advertiser_id', 'stat_time_day']),
            'metrics' => json_encode(['spend', 'impressions', 'complete_payment', 'total_complete_payment_rate', 'currency']),
            'start_date' => $from->toDateString(),
            'end_date' => $to->toDateString(),
        ]);

        $out = [];
        foreach ($rows as $r) {
            $day = $r['dimensions']['stat_time_day'] ?? null;
            if (empty($day)) {
                continue;
            }
            $m = $r['metrics'] ?? [];
            $out[] = new AccountDailyTotal(
                date: substr((string) $day, 0, 10),
                spend: (float) ($m['spend'] ?? 0),
                impressions: (int) ($m['impressions'] ?? 0),
                purchases: (float) ($m['complete_payment'] ?? 0),
                purchaseValue: (float) ($m['total_complete_payment_rate'] ?? 0),
                currency: ! empty($m['currency']) ? (string) $m['currency'] : ($a->currency ?: null),
            );
        }

        return $out;
    }

    /**
     * Nightly sweep lists (A2). ad/get leaves deleted ads out by default, so the extra ads list is STATUS_DELETE only
     * (full list + this one = every status); the campaigns list is STATUS_ALL. Each list fails alone into a warning.
     */
    public function statuses(AdAccount $a): array
    {
        $token = $this->token($a->connection);
        $out = ['ads' => null, 'campaigns' => null, 'warnings' => []];

        try {
            $ads = [];
            $unexpected = 0;
            foreach ($this->paginate($token, 'ad/get/', [
                'advertiser_id' => $a->external_id,
                'fields' => json_encode(['ad_id', 'operation_status', 'secondary_status']),
                'filtering' => json_encode(['primary_status' => 'STATUS_DELETE']),
            ]) as $r) {
                if (empty($r['ad_id'])) {
                    continue;
                }
                // Never trust the filter alone: a row that does not say it is deleted is not written as deleted.
                if (! self::isDeleted($r)) {
                    $unexpected++;

                    continue;
                }
                $ads[(string) $r['ad_id']] = ['status' => 'DELETED', 'effective_status' => 'DELETED'];
            }
            $out['ads'] = $ads;
            $out['ads_complete'] = $unexpected === 0;
            if ($unexpected > 0) {
                $out['warnings'][] = "deleted ads list: {$unexpected} rows were not deleted ads (filter ignored?); nothing is marked GONE this run";
            }
        } catch (TokenInvalid|RateLimited $e) {
            throw $e;
        } catch (AdsApiException $e) {
            $out['warnings'][] = 'deleted ads list: '.$e->getMessage();
        }

        try {
            $campaigns = [];
            foreach ($this->paginate($token, 'campaign/get/', [
                'advertiser_id' => $a->external_id,
                'fields' => json_encode(['campaign_id', 'campaign_name', 'operation_status', 'secondary_status', 'objective_type']),
                'filtering' => json_encode(['primary_status' => 'STATUS_ALL']),
            ]) as $r) {
                if (! empty($r['campaign_id'])) {
                    $campaigns[(string) $r['campaign_id']] = [
                        'name' => $r['campaign_name'] ?? null, 'status' => self::ownStatus($r),
                        'effective_status' => self::effective($r['secondary_status'] ?? null), 'objective' => $r['objective_type'] ?? null,
                    ];
                }
            }
            $out['campaigns'] = $campaigns;
        } catch (TokenInvalid|RateLimited $e) {
            throw $e;
        } catch (AdsApiException $e) {
            $out['warnings'][] = 'campaigns list: '.$e->getMessage();
        }

        return $out;
    }

    public function admit(AdAccount $a): void {}

    public function drainWarnings(): array
    {
        $w = $this->warnings;
        $this->warnings = [];

        return $w;
    }

    /** Hourly light campaign list (A2): every not-deleted campaign with its own and effective status. */
    public function campaignStatuses(AdAccount $a): ?array
    {
        $out = [];
        foreach ($this->paginate($this->token($a->connection), 'campaign/get/', [
            'advertiser_id' => $a->external_id,
            'fields' => json_encode(['campaign_id', 'operation_status', 'secondary_status']),
            'filtering' => json_encode(['primary_status' => 'STATUS_NOT_DELETE']),
        ]) as $r) {
            if (! empty($r['campaign_id'])) {
                $out[(string) $r['campaign_id']] = ['status' => self::ownStatus($r), 'effective_status' => self::effective($r['secondary_status'] ?? null)];
            }
        }

        return $out;
    }

    /**
     * TikTok's secondary statuses in the Ads Hub's (Meta) spelling where the screens and checks look for one: delivering
     * = ACTIVE, deleted = DELETED, review rejected = DISAPPROVED, in review = PENDING_REVIEW (ASSUMPTION: the
     * documented AD_STATUS_AUDIT_DENY / AD_STATUS_AUDIT / AD_STATUS_REAUDIT names). Every other value is kept as sent.
     */
    public static function effective(mixed $secondary): ?string
    {
        if (! is_string($secondary) || $secondary === '') {
            return null;
        }
        if (str_ends_with($secondary, '_DELETE')) {
            return 'DELETED';
        }

        return match ($secondary) {
            'AD_STATUS_DELIVERY_OK', 'ADGROUP_STATUS_DELIVERY_OK', 'CAMPAIGN_STATUS_ENABLE' => 'ACTIVE',
            'AD_STATUS_AUDIT_DENY' => 'DISAPPROVED',
            'AD_STATUS_AUDIT', 'AD_STATUS_REAUDIT' => 'PENDING_REVIEW',
            default => $secondary,
        };
    }

    /**
     * The object's own status as stored: TikTok's ENABLE / DISABLE, or DELETED when the secondary status says so (TikTok
     * keeps ENABLE/DISABLE on a deleted object, which would count it in the active-campaign scope).
     */
    public static function ownStatus(array $row): ?string
    {
        if (self::isDeleted($row)) {
            return 'DELETED';
        }

        return isset($row['operation_status']) ? (string) $row['operation_status'] : null;
    }

    private static function isDeleted(array $row): bool
    {
        return str_ends_with((string) ($row['secondary_status'] ?? ''), '_DELETE');
    }

    public function creativeMedia(AdAccount $a, array $adExternalIds): array
    {
        $adExternalIds = array_values(array_unique($adExternalIds));
        $stored = Ad::query()->where('ad_account_id', $a->id)->whereIn('external_id', $adExternalIds)
            ->get()->keyBy('external_id');

        $videoOf = [];
        $imageOf = [];
        foreach ($adExternalIds as $id) {
            $raw = $stored->get($id)?->raw ?? [];
            $video = $raw['video_id'] ?? null;
            if ($video) {
                $videoOf[$id] = (string) $video;
            } elseif (! empty($raw['image_ids'][0])) {
                $imageOf[$id] = (string) $raw['image_ids'][0];
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

        $byImage = [];
        foreach (array_chunk(array_values(array_unique($imageOf)), 100) as $chunk) {
            $data = $this->get($token, 'file/image/ad/info/', [
                'advertiser_id' => $a->external_id,
                'image_ids' => json_encode($chunk),
            ]);
            foreach ($data['list'] ?? [] as $img) {
                if (! empty($img['image_id'])) {
                    $byImage[(string) $img['image_id']] = $img;
                }
            }
        }

        $out = [];
        foreach ($adExternalIds as $id) {
            $v = isset($videoOf[$id]) ? ($byVideo[$videoOf[$id]] ?? []) : [];
            $image = isset($imageOf[$id]) ? ($byImage[$imageOf[$id]]['image_url'] ?? null) : null;
            $out[] = new CreativeMedia(
                adExternalId: $id,
                videoUrl: $v['preview_url'] ?? null,
                thumbnailUrl: $v['video_cover_url'] ?? $image,
                previewUrl: $v['preview_url'] ?? null,
                imageUrl: $image,
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

    /**
     * Status rows of the parents the ads name, keyed by id; a failed read is a warning and leaves those fields empty.
     *
     * @param  list<mixed>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function parentRows(string $token, AdAccount $a, string $path, string $filter, string $key, array $fields, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
        $out = [];
        try {
            foreach (array_chunk($ids, 100) as $chunk) {
                foreach ($this->paginate($token, $path, [
                    'advertiser_id' => $a->external_id,
                    'fields' => json_encode($fields),
                    'filtering' => json_encode([$filter => $chunk]),
                ]) as $r) {
                    if (! empty($r[$key])) {
                        $out[(string) $r[$key]] = $r;
                    }
                }
            }
        } catch (TokenInvalid|RateLimited $e) {
            throw $e;
        } catch (AdsApiException $e) {
            $this->warnings[] = rtrim($path, '/').': '.$e->getMessage();
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $campaign  its campaign/get row ([] when unknown)
     * @param  array<string, mixed>  $group  its adgroup/get row ([] when unknown)
     */
    private function mapAd(array $r, array $campaign = [], array $group = []): AdRow
    {
        $video = ! empty($r['video_id']) ? (string) $r['video_id'] : null;
        $created = $r['create_time'] ?? null;

        return new AdRow(
            externalId: (string) $r['ad_id'],
            name: (string) ($r['ad_name'] ?? $r['ad_id']),
            status: self::ownStatus($r),
            effectiveStatus: self::effective($r['secondary_status'] ?? null),
            campaignId: isset($r['campaign_id']) ? (string) $r['campaign_id'] : null,
            campaignName: $r['campaign_name'] ?? null,
            campaignStatus: $campaign === [] ? null : self::ownStatus($campaign),
            objective: $campaign['objective_type'] ?? null,
            adSetId: isset($r['adgroup_id']) ? (string) $r['adgroup_id'] : null,
            adSetName: $r['adgroup_name'] ?? null,
            adSetStatus: $group === [] ? null : self::ownStatus($group),
            type: $video ? 'video' : 'image',
            // TikTok ads have no headline; the identity name is the line shown above the text (ASSUMPTION).
            headline: $r['display_name'] ?? null,
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
