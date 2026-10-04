<?php

namespace App\Ads\Control\Jobs;

use App\Ads\Control\PublicationLinker;
use App\Ads\Platforms\AdPlatform;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\Data\AdDraft;
use App\Ads\Platforms\Data\Identity;
use App\Ads\Platforms\Data\MediaRef;
use App\Ads\Platforms\DriverFactory;
use App\Ads\Platforms\RateLimited;
use App\Ads\Platforms\SecretScrubber;
use App\Ads\Sync\SyncAdAccount;
use App\Models\AdAccount;
use App\Models\AdPublication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Publishes one ad_publications row as a PAUSED ad: upload (once per file and account) -> wait for the platform to
 * process the media -> create the paused ad -> queue a short sync so the ad shows up locally and gets linked to its material.
 */
class PublishAd implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Video processing is polled every 60 s and a rate limit waits 15 min: tries are cheap, real errors stop after 3. */
    public int $tries = 40;

    public int $maxExceptions = 3;

    /** A big video upload outlives the 60-s default worker. */
    public int $timeout = 900;

    public bool $failOnTimeout = true;

    /** Same in every copy of this job: a Redis re-delivery finds the done mark (or the running lock) and leaves. Null on old payloads. */
    public ?string $runKey = null;

    public function __construct(public int $publicationId)
    {
        $this->runKey = (string) Str::uuid();
        $this->onQueue('commercelong');

        if (config('queue.default') === 'redis') {
            $this->onConnection('redislong');
        }
    }

    public function handle(DriverFactory $drivers): void
    {
        if ($this->runKey === null) {
            $this->run($drivers);

            return;
        }

        $done = 'ads-publish-done:'.$this->runKey;
        if (Cache::has($done)) {
            return;
        }
        $running = Cache::lock('ads-publish-running:'.$this->runKey, $this->timeout + 60);
        if (! $running->get()) {
            return;
        }

        try {
            if ($this->run($drivers)) {
                Cache::put($done, true, now()->addHours(12));
            }
        } finally {
            $running->release();
        }
    }

    /** Out of tries or a crash: the row must not stay "uploading" forever. */
    public function failed(Throwable $e): void
    {
        $row = AdPublication::find($this->publicationId);
        if ($row && ! $row->isFinished()) {
            $row->update(['status' => AdPublication::ERROR, 'error' => $this->message($e)]);
        }
    }

    /** @return bool false when released for a later try */
    private function run(DriverFactory $drivers): bool
    {
        $row = AdPublication::with(['account', 'file', 'material'])->find($this->publicationId);
        if (! $row || $row->isFinished() || ! $row->account || ! $row->file) {
            return true;
        }

        $row->increment('attempts');
        $account = $row->account;

        try {
            $writer = $drivers->writer(AdPlatform::from($account->platform));

            $row->update(['status' => AdPublication::UPLOADING]);
            $cached = ((array) $row->file->platform_media)[(string) $account->id] ?? null;
            if (is_array($cached) && isset($cached['kind'], $cached['id'])) {
                $media = new MediaRef((string) $cached['kind'], (string) $cached['id'], false);
            } else {
                $media = $writer->uploadMedia($account, $row->file);
                $row->file->update(['platform_media' => [...(array) $row->file->platform_media, (string) $account->id => ['kind' => $media->kind, 'id' => $media->id]]]);
            }

            $row->update(['status' => AdPublication::PROCESSING]);
            if (! $media->ready && ! $writer->mediaReady($account, $media)) {
                if ($this->job && ! $this->job instanceof SyncJob) {
                    $this->release(60);

                    return false;
                }
                $row->update(['status' => AdPublication::ERROR, 'error' => __('ads.publish.media_not_ready')]);

                return true;
            }

            $row->update(['status' => AdPublication::CREATING]);
            $identity = (array) $row->identity;
            $adId = $writer->createPausedAd($account, new AdDraft(
                adSetId: $row->adset_external_id, name: $row->ad_name,
                identity: new Identity((string) ($identity['page_id'] ?? ''), (string) ($identity['page_name'] ?? ''), ($identity['instagram_id'] ?? null) ?: null),
                media: $media, primaryText: $row->primary_text, headline: $row->headline, cta: $row->cta, link: $row->link, urlTags: $row->url_tags,
            ));
        } catch (RateLimited) {
            // Only a real async queue job can be released; sync/inline runs must surface the failure.
            if ($this->job && ! $this->job instanceof SyncJob) {
                $this->release(900);

                return false;
            }
            $row->update(['status' => AdPublication::ERROR, 'error' => __('ads.errors.rate_limited')]);

            return true;
        } catch (AdsApiException $e) {
            $row->update(['status' => AdPublication::ERROR, 'error' => $this->message($e)]);

            return true;
        }

        $row->update(['status' => AdPublication::DONE, 'external_ad_id' => $adId, 'error' => null]);
        $this->followUp($row, $account);

        return true;
    }

    /** The ad exists on the platform: a failure here must not undo that. */
    private function followUp(AdPublication $row, AdAccount $account): void
    {
        try {
            app(PublicationLinker::class)->link($account);
            SyncAdAccount::dispatch($account->id, 3, 'recent', 'manual', $row->created_by_id);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function message(Throwable $e): string
    {
        $raw = trim(SecretScrubber::scrub($e->getMessage()));

        return mb_substr($raw !== '' ? $raw : $e::class, 0, 1000);
    }
}
