<?php

namespace App\Channels\Ads;

use App\Channels\Adapters\MetaGraphClient;
use App\Models\ChannelAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Which ad uses a post (owner, 2026-09-25), from the Marketing API with the channel account's
 * token — the ad accounts that token can see, then their ads' creatives, matched on the
 * creative's `effective_object_story_id` (a Facebook page post) or
 * `effective_instagram_media_id` (an Instagram media). The ads index of each account is cached
 * for a while: one comment storm on one ad must not page through the account again and again.
 * Best effort: a token without ads_read, or no ad accounts, yields null.
 */
class AdLookup
{
    /** Pages of 100 ads read per ad account at most (a busy account has thousands of archived ads). */
    public const MAX_PAGES = 8;

    public const CACHE_MINUTES = 30;

    public function __construct(private readonly MetaGraphClient $graph) {}

    /** @return array{ad_id:string, ad_name:?string, adset_name:?string, campaign_name:?string}|null */
    public function adForPost(ChannelAccount $account, string $postExternalId): ?array
    {
        foreach ($this->adAccounts($account) as $actId) {
            $index = $this->adsIndex($account, $actId);

            if (isset($index[$postExternalId])) {
                return $index[$postExternalId];
            }
        }

        return null;
    }

    /** @return list<string> `act_…` ids the token can see */
    private function adAccounts(ChannelAccount $account): array
    {
        return Cache::remember('ads:accounts:'.$account->id, now()->addMinutes(self::CACHE_MINUTES), function () use ($account) {
            try {
                $response = $this->graph->get($account, 'me/adaccounts', ['fields' => 'id', 'limit' => 50]);
            } catch (Throwable $e) {
                Log::info('ad_lookup.accounts_failed', ['account_id' => $account->id, 'error' => $e->getMessage()]);

                return [];
            }

            if (! $response->successful()) {
                Log::info('ad_lookup.accounts_refused', ['account_id' => $account->id, 'status' => $response->status(), 'error' => $response->json('error.message')]);

                return [];
            }

            return collect((array) $response->json('data', []))->pluck('id')->filter()->map(fn ($id) => (string) $id)->values()->all();
        });
    }

    /** @return array<string, array{ad_id:string, ad_name:?string, adset_name:?string, campaign_name:?string}> post/media id → ad */
    private function adsIndex(ChannelAccount $account, string $actId): array
    {
        return Cache::remember('ads:index:'.$account->id.':'.$actId, now()->addMinutes(self::CACHE_MINUTES), function () use ($account, $actId) {
            $index = [];
            $after = null;

            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                try {
                    $response = $this->graph->get($account, $actId.'/ads', array_filter([
                        'fields' => 'name,adset{name},campaign{name},creative{effective_object_story_id,effective_instagram_media_id}',
                        'limit' => 100,
                        'after' => $after,
                    ]));
                } catch (Throwable $e) {
                    Log::info('ad_lookup.ads_failed', ['account_id' => $account->id, 'act' => $actId, 'error' => $e->getMessage()]);
                    break;
                }

                if (! $response->successful()) {
                    Log::info('ad_lookup.ads_refused', ['account_id' => $account->id, 'act' => $actId, 'status' => $response->status(), 'error' => $response->json('error.message')]);
                    break;
                }

                foreach ((array) $response->json('data', []) as $ad) {
                    $row = [
                        'ad_id' => (string) ($ad['id'] ?? ''),
                        'ad_name' => $this->cut($ad['name'] ?? null),
                        'adset_name' => $this->cut($ad['adset']['name'] ?? null),
                        'campaign_name' => $this->cut($ad['campaign']['name'] ?? null),
                    ];

                    foreach (['effective_object_story_id', 'effective_instagram_media_id'] as $key) {
                        $id = $ad['creative'][$key] ?? null;

                        // The first (newest) ad on a post wins; an older duplicate never overwrites it.
                        if (is_string($id) && $id !== '' && ! isset($index[$id])) {
                            $index[$id] = $row;
                        }
                    }
                }

                $after = $response->json('paging.cursors.after');

                if (! is_string($after) || $after === '') {
                    break;
                }
            }

            return $index;
        });
    }

    private function cut(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 190) : null;
    }
}
