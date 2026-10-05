<?php

namespace App\Ads\Platforms\Fake;

use App\Ads\Platforms\AdPlatform;
use App\Ads\Platforms\AdPlatformDriver;
use App\Ads\Platforms\AdPlatformWriter;
use App\Ads\Platforms\Data\AccountDailyTotal;
use App\Ads\Platforms\Data\AccountInfo;
use App\Ads\Platforms\Data\AdDraft;
use App\Ads\Platforms\Data\AdRow;
use App\Ads\Platforms\Data\CampaignNode;
use App\Ads\Platforms\Data\CreativeMedia;
use App\Ads\Platforms\Data\DailyAdMetric;
use App\Ads\Platforms\Data\Identity;
use App\Ads\Platforms\Data\MediaRef;
use App\Models\AdAccount;
use App\Models\AdMaterialFile;
use App\Models\AdPlatformConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Deterministic demo data (no HTTP at all). Everything derives from
 * crc32 of the account external id, so repeated calls return identical rows.
 * The picsum URLs are demo thumbnails only; nothing here fetches them.
 */
class FakeAdsDriver implements AdPlatformDriver, AdPlatformWriter
{
    private const WRITER_KEY = 'ads-fake-writer';

    public const META_CLOTING = 'act_demo_cloting';

    public const META_MAIN = 'act_demo_main';

    public const META_MAIN_22 = 'act_demo_main22';

    public const TIKTOK = 'tt_demo_1';

    public const GOOGLE = 'gg-demo-1';

    public function accounts(AdPlatformConnection $c): array
    {
        $list = match (AdPlatform::from($c->platform)) {
            // Obviously fake ids and names: a fake run can never collide with (or pass for) a real account.
            AdPlatform::Meta => [
                [self::META_CLOTING, 'Cloting (تجريبي)'],
                [self::META_MAIN, 'Lv Main (تجريبي)'],
                [self::META_MAIN_22, 'Lv Main 22 (تجريبي)'],
            ],
            AdPlatform::Tiktok => [[self::TIKTOK, 'Le Voile TikTok (تجريبي)']],
            AdPlatform::Google => [[self::GOOGLE, 'Le Voile Google (تجريبي)']],
        };

        return array_map(fn ($r) => new AccountInfo($r[0], $r[1], 'EGP', 'Africa/Cairo', 'active', 0.0), $list);
    }

    public function ads(AdAccount $a): array
    {
        $rows = [];
        foreach ($this->structure($a) as $ad) {
            $rows[] = new AdRow(
                externalId: $ad['id'], name: $ad['name'], status: 'ACTIVE', effectiveStatus: 'ACTIVE',
                campaignId: $ad['campaign_id'], campaignName: $ad['campaign_name'], campaignStatus: 'ACTIVE', objective: 'OUTCOME_SALES',
                adSetId: $ad['adset_id'], adSetName: $ad['adset_name'], adSetStatus: 'ACTIVE',
                type: $ad['type'],
                headline: 'Le Voile '.$ad['n'], body: 'Demo creative '.$ad['n'],
                thumbnailUrl: "https://picsum.photos/seed/{$ad['id']}/400/400", imageUrl: "https://picsum.photos/seed/{$ad['id']}/800/800",
                videoId: $ad['type'] === 'video' ? 'vid_'.$ad['id'] : null, objectStoryId: null, instagramPermalinkUrl: null,
                urlTags: null,
                carousel: $ad['type'] === 'carousel' ? array_map(fn ($k) => [
                    'image_url' => "https://picsum.photos/seed/{$ad['id']}-{$k}/600/600",
                    'link' => 'https://example.com/products/'.$k,
                    'name' => 'Item '.$k,
                ], [1, 2, 3]) : null, createdTime: '2026-08-01T10:00:00+0000',
            );
        }

        return $rows;
    }

    public function dailyMetrics(AdAccount $a, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $structure = $this->structure($a);
        $out = [];
        for ($day = $from->startOfDay(); $day->lte($to); $day = $day->addDay()) {
            foreach ($structure as $ad) {
                // Seeded per (account, ad, date) so overlapping ranges agree.
                $rng = new Randomizer(new Mt19937(crc32($a->external_id.'|'.$ad['id'].'|'.$day->toDateString())));
                $spend = round($rng->getInt(30000, 300000) / 100, 2);              // 300 - 3000
                $impressions = $rng->getInt(20, 60) * (int) $spend;
                $clicks = (int) round($impressions * $rng->getInt(100, 800) / 10000); // CTR 1 - 8 %
                $reach = (int) round($impressions * $rng->getInt(70, 90) / 100);
                // Each ad has its own quality (ROAS roughly 1 to 4.5), so winners and losers both show in demos.
                $quality = [0.35, 0.6, 0.85, 1.0, 1.2, 1.5][crc32($ad['id']) % 6];
                $purchases = max(0, round($spend / 150 * $quality + $rng->getInt(-10, 10) / 10, 0));
                // Drawn after the older fields so their seeded values stay the same.
                $linkClicks = (int) round($clicks * $rng->getInt(60, 90) / 100);
                $msgConversations = (int) round($linkClicks * $rng->getInt(0, 15) / 100);
                $out[] = new DailyAdMetric(
                    adExternalId: $ad['id'], date: $day->toDateString(),
                    spend: $spend, impressions: $impressions, clicks: $clicks, reach: $reach,
                    purchases: (float) $purchases, purchaseValue: (float) ($purchases * 450),
                    adName: $ad['name'], campaignId: $ad['campaign_id'], campaignName: $ad['campaign_name'],
                    adSetId: $ad['adset_id'], adSetName: $ad['adset_name'],
                    linkClicks: $linkClicks, msgConversations: $msgConversations,
                );
            }
        }

        return $out;
    }

    /** The control is the sum of the fake ad rows, so a fake sync always agrees with itself. */
    public function accountDaily(AdAccount $a, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        $days = [];
        foreach ($this->dailyMetrics($a, $from, $to) as $m) {
            $date = substr($m->date, 0, 10);
            $d = $days[$date] ?? ['spend' => 0.0, 'impressions' => 0, 'purchases' => 0.0, 'value' => 0.0];
            $days[$date] = [
                'spend' => $d['spend'] + $m->spend, 'impressions' => $d['impressions'] + $m->impressions,
                'purchases' => $d['purchases'] + $m->purchases, 'value' => $d['value'] + $m->purchaseValue,
            ];
        }
        ksort($days);
        $out = [];
        foreach ($days as $date => $d) {
            $out[] = new AccountDailyTotal((string) $date, round($d['spend'], 2), $d['impressions'], $d['purchases'], round($d['value'], 2), $a->currency ?: 'EGP');
        }

        return $out;
    }

    /** Fake ads never archive; every fake campaign is listed as active. */
    public function statuses(AdAccount $a): array
    {
        $campaigns = [];
        foreach ($this->structure($a) as $ad) {
            $campaigns[(string) $ad['campaign_id']] = ['name' => (string) $ad['campaign_name'], 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'objective' => 'OUTCOME_SALES'];
        }

        return ['ads' => [], 'campaigns' => $campaigns];
    }

    /** Every fake campaign is active. */
    public function campaignStatuses(AdAccount $a): ?array
    {
        $out = [];
        foreach ($this->structure($a) as $ad) {
            $out[(string) $ad['campaign_id']] = ['status' => 'ACTIVE', 'effective_status' => 'ACTIVE'];
        }

        return $out;
    }

    public function creativeMedia(AdAccount $a, array $adExternalIds): array
    {
        return array_map(fn ($id) => new CreativeMedia(
            adExternalId: (string) $id,
            imageUrl: "https://picsum.photos/seed/{$id}/800/800",
            thumbnailUrl: "https://picsum.photos/seed/{$id}/400/400",
        ), array_values($adExternalIds));
    }

    public function liveCampaigns(AdAccount $a): array
    {
        $nodes = [];
        foreach ($this->structure($a) as $ad) {
            $nodes[$ad['campaign_id']] ??= new CampaignNode($ad['campaign_id'], (string) $ad['campaign_name'], 'ACTIVE', 'OUTCOME_SALES', []);
            $node = $nodes[$ad['campaign_id']];
            if (! in_array($ad['adset_id'], array_column($node->adSets, 'id'), true)) {
                $node->adSets[] = ['id' => (string) $ad['adset_id'], 'name' => (string) $ad['adset_name'], 'status' => 'ACTIVE'];
            }
        }

        return array_values($nodes);
    }

    public function identities(AdAccount $a): array
    {
        return [new Identity('fake_page_1', 'Le Voile (تجريبي)', 'fake_ig_1')];
    }

    public function uploadMedia(AdAccount $a, AdMaterialFile $file): MediaRef
    {
        $state = $this->writerState();
        $n = ++$state['media'];
        $this->saveWriterState($state);
        $video = str_starts_with((string) $file->mime, 'video/');

        return new MediaRef($video ? 'video' : 'image', ($video ? 'fake_video_' : 'fake_image_').$n, ! $video);
    }

    public function mediaReady(AdAccount $a, MediaRef $ref): bool
    {
        return true;
    }

    public function createPausedAd(AdAccount $a, AdDraft $draft): string
    {
        $draft->adRequestSending();
        $state = $this->writerState();
        $id = 'fake_ad_'.++$state['n'];
        $state['ads'][$id] = [
            'account' => $a->external_id, 'adset_id' => $draft->adSetId, 'name' => $draft->name, 'status' => 'paused',
            'media' => $draft->media->id, 'primary_text' => $draft->primaryText, 'headline' => $draft->headline,
            'cta' => $draft->cta, 'link' => $draft->link, 'url_tags' => $draft->urlTags, 'page_id' => $draft->identity->pageId,
        ];
        $this->saveWriterState($state);

        return $id;
    }

    public function setStatus(AdAccount $a, string $level, string $externalId, string $status): void
    {
        $state = $this->writerState();
        $state['statuses'][] = ['level' => $level, 'id' => $externalId, 'status' => $status];
        $this->saveWriterState($state);
    }

    /** @return array{n:int,media:int,ads:array<string,array<string,mixed>>,statuses:list<array<string,string>>} */
    private function writerState(): array
    {
        return (Cache::get(self::WRITER_KEY) ?? []) + ['n' => 0, 'media' => 0, 'ads' => [], 'statuses' => []];
    }

    private function saveWriterState(array $state): void
    {
        Cache::forever(self::WRITER_KEY, $state);
    }

    public function test(AdPlatformConnection $c): ?string
    {
        return null;
    }

    /**
     * 3 campaigns x 2 ad sets x 3 ads = 18 ads, ids derived from the account.
     *
     * @return list<array<string, string|int>>
     */
    private function structure(AdAccount $a): array
    {
        $seed = crc32($a->external_id);
        $campaignNames = match (AdPlatform::from($a->platform)) {
            AdPlatform::Tiktok => ['TikTok Main', 'TikTok Retargeting', 'TikTok Reach'],
            AdPlatform::Google => ['Google Search', 'Google Shopping', 'Google Brand'],
            AdPlatform::Meta => ['Sales - Main', 'Retargeting', 'Reach'],
        };
        $types = ['image', 'video', 'carousel'];
        $out = [];
        $n = 0;
        for ($c = 1; $c <= 3; $c++) {
            for ($s = 1; $s <= 2; $s++) {
                for ($i = 1; $i <= 3; $i++) {
                    $n++;
                    $out[] = [
                        'n' => $n,
                        'id' => (string) ($seed % 100000 * 1000 + $n).'0'.$c.$s.$i,
                        'name' => "Ad {$n}",
                        'type' => $types[($n + $seed) % 3],
                        'campaign_id' => (string) ($seed % 100000 * 100 + $c),
                        'campaign_name' => $campaignNames[$c - 1],
                        'adset_id' => (string) ($seed % 100000 * 100 + $c * 10 + $s),
                        'adset_name' => "{$campaignNames[$c - 1]} - Set {$s}",
                    ];
                }
            }
        }

        return $out;
    }
}
