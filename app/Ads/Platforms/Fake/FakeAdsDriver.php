<?php

namespace App\Ads\Platforms\Fake;

use App\Ads\Platforms\AdPlatform;
use App\Ads\Platforms\AdPlatformDriver;
use App\Ads\Platforms\Data\AccountInfo;
use App\Ads\Platforms\Data\AdRow;
use App\Ads\Platforms\Data\CreativeMedia;
use App\Ads\Platforms\Data\DailyAdMetric;
use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use Carbon\CarbonImmutable;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Deterministic demo data (no HTTP at all). Everything derives from
 * crc32 of the account external id, so repeated calls return identical rows.
 * The picsum URLs are demo thumbnails only; nothing here fetches them.
 */
class FakeAdsDriver implements AdPlatformDriver
{
    public function accounts(AdPlatformConnection $c): array
    {
        $list = match (AdPlatform::from($c->platform)) {
            AdPlatform::Meta => [
                ['act_1648538895706851', 'Cloting'],
                ['act_6746411735418687', 'Lv Main'],
                ['act_950240346866068', 'Lv Main 22'],
            ],
            AdPlatform::Tiktok => [['7400000000000000001', 'Le Voile TikTok']],
            AdPlatform::Google => [['123-456-7890', 'Le Voile Google']],
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
                $purchases = max(0, round($spend / 150 + $rng->getInt(-10, 10) / 10, 0));
                $out[] = new DailyAdMetric(
                    adExternalId: $ad['id'], date: $day->toDateString(),
                    spend: $spend, impressions: $impressions, clicks: $clicks, reach: $reach,
                    purchases: (float) $purchases, purchaseValue: (float) ($purchases * 450),
                    adName: $ad['name'], campaignId: $ad['campaign_id'], campaignName: $ad['campaign_name'],
                    adSetId: $ad['adset_id'], adSetName: $ad['adset_name'],
                );
            }
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
                        'id' => (string) ($seed % 100000 * 1000 + $n) . '0'.$c.$s.$i,
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
