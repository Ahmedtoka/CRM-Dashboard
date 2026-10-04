<?php

namespace App\Ads\Sync;

use App\Ads\Platforms\AdPlatform;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\Data\AdRow;
use App\Ads\Platforms\Data\CreativeMedia;
use App\Ads\Platforms\Data\DailyAdMetric;
use App\Ads\Platforms\DriverFactory;
use App\Ads\Platforms\PreviewMarkup;
use App\Ads\Platforms\RateLimited;
use App\Ads\Platforms\SecretScrubber;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdCampaign;
use App\Models\AdDailyMetric;
use App\Models\AdPlatformConnection;
use App\Models\AdSet;
use App\Models\AdsSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AdsSyncService
{
    private const CHUNK = 500;

    public function __construct(private DriverFactory $drivers) {}

    /** Message safe to print: credentials-looking pairs removed, length capped. */
    public static function scrub(string $message): string
    {
        $message = SecretScrubber::scrub($message);
        $clean = preg_replace('/(access_token|token|secret|key|authorization)([=:\s]+)[^\s&,;"]+/i', '$1$2[hidden]', $message);

        return mb_substr($clean ?? $message, 0, 200);
    }

    /** Pull the connection's accounts and upsert ad_accounts. @return int accounts upserted */
    public function syncAccounts(AdPlatformConnection $c): int
    {
        try {
            $infos = $this->drivers->for(AdPlatform::from($c->platform))->accounts($c);
        } catch (AdsApiException $e) {
            $c->update($e instanceof RateLimited
                ? ['last_error' => $e->getMessage()]
                : ['status' => 'error', 'last_error' => $e->getMessage()]);
            throw $e;
        }

        foreach ($infos as $i) {
            $account = AdAccount::firstOrNew(['platform' => $c->platform, 'external_id' => $i->externalId]);
            $account->fill([
                'connection_id' => $c->id, 'name' => $i->name, 'currency' => $i->currency,
                'timezone' => $i->timezone, 'status' => $i->status, 'balance' => $i->balance,
            ])->save();
        }

        return count($infos);
    }

    /** ads + campaigns + adsets, then daily metrics for [from,to] (replacing the account's rows of those dates), then media. */
    public function syncAccount(AdAccount $a, CarbonImmutable $from, CarbonImmutable $to, string $kind = 'recent', bool $withAds = true, string $trigger = 'schedule', ?int $triggeredById = null): AdsSyncRun
    {
        $run = AdsSyncRun::create([
            'ad_account_id' => $a->id, 'platform' => $a->platform, 'kind' => $kind, 'status' => 'running',
            'trigger' => $trigger, 'triggered_by_id' => $triggeredById,
            'from_date' => $from->toDateString(), 'to_date' => $to->toDateString(), 'started_at' => now(),
        ]);

        try {
            return $this->runSync($a, $run, $from, $to, $withAds);
        } catch (Throwable $e) {
            // Whatever escaped (bad driver config, DB error, media-phase bug) must not leave the run 'running'.
            if ($run->status === 'running') {
                $run->update(['status' => 'error', 'error' => self::scrub($e->getMessage()), 'finished_at' => now()]);
            }
            throw $e;
        }
    }

    private function runSync(AdAccount $a, AdsSyncRun $run, CarbonImmutable $from, CarbonImmutable $to, bool $withAds = true): AdsSyncRun
    {
        $driver = $this->drivers->for(AdPlatform::from($a->platform));

        try {
            // The ad list (full creative specs) is the heaviest Meta call: a backfill reads it once, on its first chunk.
            $adRows = $withAds ? $driver->ads($a) : [];
            $this->upsertAds($a, $adRows);
            $metrics = $driver->dailyMetrics($a, $from, $to);
            [$rows, $guard] = $this->replaceMetrics($a, $metrics, $from, $to);
        } catch (AdsApiException $e) {
            $run->update(['status' => 'error', 'error' => self::scrub($e->getMessage()), 'finished_at' => now()]);
            if ($e instanceof RateLimited) {
                // Quota, not a broken connection: record on the run only and let the caller retry later.
                throw $e;
            }
            $a->connection?->update(['status' => 'error', 'last_error' => $e->getMessage()]);

            return $run;
        }

        // Metrics are committed; a media failure must not turn the run into an error.
        $warnings = $guard === null ? [] : [$guard];
        try {
            $this->fetchMedia($a, Ad::where('ad_account_id', $a->id)->whereNull('media_fetched_at')
                ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'unknown')) // minimal ads have no creative to fetch
                ->pluck('external_id')->all());
        } catch (AdsApiException $e) {
            $warnings[] = 'Creative media: '.self::scrub($e->getMessage());
        }

        $run->update([
            'status' => 'ok', 'ads_count' => count($adRows), 'rows_count' => $rows, 'error' => $warnings === [] ? null : implode(' | ', $warnings), 'finished_at' => now(),
        ]);
        $a->update(['last_synced_at' => now()]);
        $a->connection?->update(['status' => 'connected', 'last_error' => null, 'last_synced_at' => now()]);

        return $run;
    }

    /** Re-fetch media for ads that had metrics in the last $days days. @return int ads refreshed */
    public function refreshCreatives(AdAccount $a, int $days): int
    {
        $since = CarbonImmutable::now('Africa/Cairo')->subDays($days)->toDateString();
        $ids = Ad::where('ad_account_id', $a->id)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'unknown'))
            ->whereIn('id', AdDailyMetric::where('ad_account_id', $a->id)->where('date', '>=', $since)->select('ad_id'))
            ->pluck('external_id')->all();

        return $this->fetchMedia($a, $ids);
    }

    /** Sync an account over $days days in 30-day chunks, newest first. */
    public function backfill(AdAccount $a, int $days, string $trigger = 'backfill', ?int $triggeredById = null): ?AdsSyncRun
    {
        $run = null;
        $today = CarbonImmutable::now('Africa/Cairo')->startOfDay();
        for ($offset = 0; $offset < $days; $offset += 30) {
            $to = $today->subDays($offset);
            $from = $today->subDays(min($offset + 29, $days - 1));
            $run = $this->syncAccount($a, $from, $to, 'backfill', withAds: $offset === 0, trigger: $trigger, triggeredById: $triggeredById);
            if ($run->status === 'error') {
                break;
            }
        }

        return $run;
    }

    /** @param list<string> $externalIds */
    private function fetchMedia(AdAccount $a, array $externalIds): int
    {
        if ($externalIds === []) {
            return 0;
        }
        $driver = $this->drivers->for(AdPlatform::from($a->platform));
        $count = 0;
        foreach (array_chunk($externalIds, 50) as $chunk) {
            foreach ($driver->creativeMedia($a, $chunk) as $m) {
                $count += $this->applyMedia($a, $m);
            }
        }

        return $count;
    }

    private function applyMedia(AdAccount $a, CreativeMedia $m): int
    {
        $values = array_filter([
            'image_url' => $m->imageUrl, 'video_url' => $m->videoUrl, 'thumbnail_url' => $m->thumbnailUrl,
            'preview_url' => $m->previewUrl, 'permalink_url' => $m->permalinkUrl,
        ], fn ($v) => $v !== null);
        // Preview markup is untrusted platform HTML: whenever a driver sends some, only a host-checked
        // iframe src (preview_url) and a rebuilt single iframe (preview_html, else null) are stored.
        if ($m->previewHtml !== null) {
            $values['preview_url'] = PreviewMarkup::iframeSrc($m->previewHtml)
                ?? (PreviewMarkup::allowedUrl($m->previewUrl) ? $m->previewUrl : null);
            $values['preview_html'] = PreviewMarkup::singleIframe($m->previewHtml);
        }
        $values['media_fetched_at'] = now();

        return Ad::where('ad_account_id', $a->id)->where('external_id', $m->adExternalId)->update($values);
    }

    /** @param list<AdRow> $rows */
    private function upsertAds(AdAccount $a, array $rows): void
    {
        $campaigns = [];
        $sets = [];
        $now = now()->toDateTimeString();
        $payload = [];
        foreach ($rows as $r) {
            $campaign = $r->campaignId !== null
                ? ($campaigns[$r->campaignId] ??= $this->campaign($a, $r->campaignId, $r->campaignName, $r->campaignStatus, $r->objective))
                : null;
            $set = ($r->adSetId !== null && $campaign)
                ? ($sets[$campaign->id.'|'.$r->adSetId] ??= $this->adSet($campaign, $r->adSetId, $r->adSetName, $r->adSetStatus))
                : null;
            $payload[] = [
                'ad_account_id' => $a->id, 'ad_campaign_id' => $campaign?->id, 'ad_set_id' => $set?->id,
                'external_id' => $r->externalId, 'name' => $r->name, 'status' => $r->status, 'effective_status' => $r->effectiveStatus,
                'type' => $r->type, 'headline' => $r->headline, 'body' => $r->body,
                'thumbnail_url' => $r->thumbnailUrl, 'image_url' => $r->imageUrl,
                'object_story_id' => $r->objectStoryId, 'instagram_permalink_url' => $r->instagramPermalinkUrl,
                'url_tags' => $r->urlTags, 'carousel' => $r->carousel === null ? null : json_encode($r->carousel),
                'created_time' => $r->createdTime ? CarbonImmutable::parse($r->createdTime)->toDateTimeString() : null,
                'raw' => json_encode($r->raw), 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        // Media columns (video/preview/permalink, media_fetched_at) stay out of the update list so a sync never wipes fetched media.
        $update = ['ad_campaign_id', 'ad_set_id', 'name', 'status', 'effective_status', 'type', 'headline', 'body', 'object_story_id', 'instagram_permalink_url', 'url_tags', 'carousel', 'created_time', 'raw', 'updated_at'];
        foreach (array_chunk($payload, self::CHUNK) as $chunk) {
            Ad::upsert($chunk, ['ad_account_id', 'external_id'], $update);
        }
    }

    /**
     * @param  list<DailyAdMetric>  $metrics
     * @return array{0: int, 1: ?string} rows written and an optional warning
     */
    private function replaceMetrics(AdAccount $a, array $metrics, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Dedupe on (ad, date); the last row wins.
        $byKey = [];
        foreach ($metrics as $m) {
            $byKey[$m->adExternalId.'|'.substr($m->date, 0, 10)] = $m;
        }

        return DB::transaction(function () use ($a, $byKey, $from, $to) {
            $adIds = Ad::where('ad_account_id', $a->id)->pluck('id', 'external_id')->all();
            $campaigns = [];
            $sets = [];
            foreach ($byKey as $m) {
                if (isset($adIds[$m->adExternalId])) {
                    continue;
                }
                $campaign = $m->campaignId !== null
                    ? ($campaigns[$m->campaignId] ??= $this->campaign($a, $m->campaignId, $m->campaignName))
                    : null;
                $set = ($m->adSetId !== null && $campaign)
                    ? ($sets[$campaign->id.'|'.$m->adSetId] ??= $this->adSet($campaign, $m->adSetId, $m->adSetName))
                    : null;
                $adIds[$m->adExternalId] = Ad::create([
                    'ad_account_id' => $a->id, 'ad_campaign_id' => $campaign?->id, 'ad_set_id' => $set?->id,
                    'external_id' => $m->adExternalId, 'name' => $m->adName ?: $m->adExternalId, 'status' => 'unknown',
                ])->id;
            }

            $now = now()->toDateTimeString();
            $payload = [];
            $keep = [];
            foreach ($byKey as $m) {
                $adId = $adIds[$m->adExternalId];
                $date = substr($m->date, 0, 10);
                $keep[$adId.'|'.$date] = true;
                $payload[] = [
                    'ad_id' => $adId, 'ad_account_id' => $a->id, 'date' => $date, 'spend' => $m->spend,
                    'impressions' => $m->impressions, 'clicks' => $m->clicks, 'reach' => $m->reach,
                    'purchases' => $m->purchases, 'purchase_value' => $m->purchaseValue,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }

            // Platform corrections win: drop rows in the window that are no longer reported.
            $stale = [];
            $guard = null;
            $windowRows = AdDailyMetric::where('ad_account_id', $a->id)->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
            if ($byKey === [] && (clone $windowRows)->exists()) {
                // An empty payload over a populated window is more likely an API hiccup than a real wipe-out.
                $guard = 'Empty metrics payload: kept existing rows in the window';
            } else {
                foreach (AdDailyMetric::where('ad_account_id', $a->id)
                    ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                    ->select(['id', 'ad_id', 'date'])->cursor() as $row) {
                    if (! isset($keep[$row->ad_id.'|'.$row->date->toDateString()])) {
                        $stale[] = $row->id;
                    }
                }
            }
            foreach (array_chunk($stale, self::CHUNK) as $ids) {
                AdDailyMetric::whereIn('id', $ids)->delete();
            }

            foreach (array_chunk($payload, self::CHUNK) as $chunk) {
                AdDailyMetric::upsert($chunk, ['ad_id', 'date'], ['ad_account_id', 'spend', 'impressions', 'clicks', 'reach', 'purchases', 'purchase_value', 'updated_at']);
            }

            return [count($payload), $guard];
        });
    }

    private function campaign(AdAccount $a, string $externalId, ?string $name, ?string $status = null, ?string $objective = null): AdCampaign
    {
        $c = AdCampaign::firstOrNew(['ad_account_id' => $a->id, 'external_id' => $externalId]);
        $c->name = $name ?: ($c->name ?: $externalId);
        if ($status !== null) {
            $c->status = $status;
        }
        if ($objective !== null) {
            $c->objective = $objective;
        }
        $c->save();

        return $c;
    }

    private function adSet(AdCampaign $campaign, string $externalId, ?string $name, ?string $status = null): AdSet
    {
        $s = AdSet::firstOrNew(['ad_campaign_id' => $campaign->id, 'external_id' => $externalId]);
        $s->name = $name ?: ($s->name ?: $externalId);
        if ($status !== null) {
            $s->status = $status;
        }
        $s->save();

        return $s;
    }
}
