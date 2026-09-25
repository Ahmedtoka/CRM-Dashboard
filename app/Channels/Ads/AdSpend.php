<?php

namespace App\Channels\Ads;

use App\Channels\Adapters\MetaGraphClient;
use App\Models\ChannelAccount;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Spend per campaign for a period (owner, 2026-09-25), from the Marketing API insights of every
 * ad account the channel account's token can see. Cached an hour per account and period.
 * Best effort: a token without ads_read yields an empty list.
 */
class AdSpend
{
    public const CACHE_MINUTES = 60;

    public function __construct(private readonly MetaGraphClient $graph) {}

    /** @return array<string, array{campaign_id:string, campaign_name:string, spend:float, currency:?string}> by campaign name */
    public function byCampaign(ChannelAccount $account, CarbonInterface $from, CarbonInterface $to): array
    {
        $key = 'ads:spend:'.$account->id.':'.$from->toDateString().':'.$to->toDateString();

        return Cache::remember($key, now()->addMinutes(self::CACHE_MINUTES), function () use ($account, $from, $to) {
            $rows = [];

            foreach ($this->adAccounts($account) as $actId) {
                try {
                    $response = $this->graph->get($account, $actId.'/insights', [
                        'level' => 'campaign',
                        'fields' => 'campaign_id,campaign_name,spend,account_currency',
                        'time_range' => json_encode(['since' => $from->toDateString(), 'until' => $to->toDateString()]),
                        'limit' => 500,
                    ]);
                } catch (Throwable $e) {
                    Log::info('ad_spend.failed', ['account_id' => $account->id, 'act' => $actId, 'error' => $e->getMessage()]);

                    continue;
                }

                if (! $response->successful()) {
                    Log::info('ad_spend.refused', ['account_id' => $account->id, 'act' => $actId, 'status' => $response->status(), 'error' => $response->json('error.message')]);

                    continue;
                }

                foreach ((array) $response->json('data', []) as $row) {
                    $name = trim((string) ($row['campaign_name'] ?? ''));

                    if ($name === '') {
                        continue;
                    }

                    $rows[$name] = [
                        'campaign_id' => (string) ($row['campaign_id'] ?? ''),
                        'campaign_name' => $name,
                        'spend' => round((float) ($rows[$name]['spend'] ?? 0) + (float) ($row['spend'] ?? 0), 2),
                        'currency' => isset($row['account_currency']) ? (string) $row['account_currency'] : null,
                    ];
                }
            }

            return $rows;
        });
    }

    /** @return list<string> */
    private function adAccounts(ChannelAccount $account): array
    {
        return Cache::remember('ads:accounts:'.$account->id, now()->addMinutes(AdLookup::CACHE_MINUTES), function () use ($account) {
            try {
                $response = $this->graph->get($account, 'me/adaccounts', ['fields' => 'id', 'limit' => 50]);
            } catch (Throwable $e) {
                return [];
            }

            return $response->successful()
                ? collect((array) $response->json('data', []))->pluck('id')->filter()->map(fn ($id) => (string) $id)->values()->all()
                : [];
        });
    }
}
