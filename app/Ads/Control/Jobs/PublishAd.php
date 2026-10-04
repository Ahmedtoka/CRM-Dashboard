<?php

namespace App\Ads\Control\Jobs;

use App\Ads\Control\PublicationLinker;
use App\Ads\Platforms\AdPlatform;
use App\Ads\Platforms\AdPlatformWriter;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\CreativeRejected;
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
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Publishes one ad_publications row as a PAUSED ad: upload (once per file and account) -> wait for the platform to
 * process the media -> create the paused ad -> queue a short sync so the ad shows up locally and gets linked to its material.
 *
 * The create step is never repeated: a row found in `creating` means a previous attempt stopped mid-create, so it ends in
 * error. Only when `ad_requested_at` is set (stamped by the writer right before the ad-create request goes out) can an ad
 * exist on the platform, and only then does the error warn that it "may already exist". A failure before that point
 * (thumbnail, cover, creative, a usage back-off: CreativeRejected) never carries the warning.
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
            $text = $e instanceof MaxAttemptsExceededException ? __('ads.publish.media_not_ready') : $this->message($e);
            if ($row->status === AdPublication::CREATING && $row->ad_requested_at !== null) {
                $text = mb_substr($text, 0, 800).' '.__('ads.publish.create_may_exist');
            }
            $row->update(['status' => AdPublication::ERROR, 'error' => $text]);
        }
    }

    /** @return bool false when released for a later try */
    private function run(DriverFactory $drivers): bool
    {
        $row = AdPublication::with(['account.connection', 'file', 'material'])->find($this->publicationId);
        if (! $row || $row->isFinished()) {
            return true;
        }
        if (! $row->account || ! $row->file) {
            $row->update(['status' => AdPublication::ERROR, 'error' => __(! $row->account ? 'ads.publish.account_gone' : 'ads.publish.file_gone')]);

            return true;
        }

        // A previous attempt stopped while creating: never create twice. The ad can only exist if its request was sent.
        if ($row->status === AdPublication::CREATING) {
            $row->update(['status' => AdPublication::ERROR, 'error' => $row->ad_requested_at !== null
                ? __('ads.publish.stopped_creating', ['name' => $row->ad_name])
                : __('ads.publish.stopped_before_ad')]);

            return true;
        }

        $account = $row->account;
        // Re-checked at run time: the account or its connection may have been switched off after queueing.
        if (! $account->is_active || ! $account->connection || $account->connection->status === 'disabled') {
            $row->update(['status' => AdPublication::ERROR, 'error' => __('ads.publish.account_inactive')]);

            return true;
        }

        $row->increment('attempts');
        $step = 'upload';

        try {
            $writer = $drivers->writer(AdPlatform::from($account->platform));

            $row->update(['status' => AdPublication::UPLOADING]);
            $cached = ((array) $row->file->platform_media)[(string) $account->id] ?? null;
            $media = is_array($cached) && isset($cached['kind'], $cached['id'])
                ? new MediaRef((string) $cached['kind'], (string) $cached['id'], false)
                : $this->upload($writer, $row);

            $row->update(['status' => AdPublication::PROCESSING]);
            if (! $media->ready && ! $this->ready($writer, $row, $media)) {
                if ($this->job && ! $this->job instanceof SyncJob) {
                    $this->release(60);

                    return false;
                }
                $row->update(['status' => AdPublication::ERROR, 'error' => __('ads.publish.media_not_ready')]);

                return true;
            }

            // Compare-and-set: only one worker may enter the create step for this row.
            $won = AdPublication::query()->whereKey($row->id)->whereNotIn('status', [AdPublication::CREATING, AdPublication::DONE])->update(['status' => AdPublication::CREATING]);
            if ($won === 0) {
                return true;
            }
            $row->status = AdPublication::CREATING;
            $step = 'create';

            $identity = (array) $row->identity;
            $poster = $media->kind === 'video' && $row->file->thumb_path ? (string) $row->file->thumb_path : null;
            $adId = $writer->createPausedAd($account, new AdDraft(
                adSetId: $row->adset_external_id, name: $row->ad_name,
                identity: new Identity((string) ($identity['page_id'] ?? ''), (string) ($identity['page_name'] ?? ''), ($identity['instagram_id'] ?? null) ?: null),
                media: $media, primaryText: $row->primary_text, headline: $row->headline, cta: $row->cta, link: $row->link, urlTags: $row->url_tags,
                posterDisk: $poster !== null ? (string) $row->file->disk : null, posterPath: $poster,
                beforeAdRequest: fn () => $row->forceFill(['ad_requested_at' => now()])->save(),
            ));
        } catch (LockTimeoutException) {
            // Another worker is still uploading this file for this account: wait for it, this is not a failure.
            if ($this->job && ! $this->job instanceof SyncJob) {
                $this->release(60);

                return false;
            }
            $row->update(['status' => AdPublication::ERROR, 'error' => __('ads.publish.upload_busy')]);

            return true;
        } catch (AdsApiException $e) {
            $sent = $step === 'create' && $row->ad_requested_at !== null;
            $text = $e instanceof RateLimited ? __('ads.errors.rate_limited') : $this->message($e);

            if ($sent) {
                // The ad-create request went out: a timeout, a rate limit or an unknown error may still have created the ad.
                $row->update(['status' => AdPublication::ERROR, 'error' => mb_substr($text, 0, 800).' '.__('ads.publish.create_may_exist')]);

                return true;
            }

            // Nothing reached the ad-create endpoint and the platform asked to slow down: safe to try again later.
            $slowDown = $step === 'create' ? $e instanceof CreativeRejected && $e->rateLimited() : $e instanceof RateLimited;
            if ($slowDown && $this->job && ! $this->job instanceof SyncJob) {
                if ($step === 'create') {
                    AdPublication::query()->whereKey($row->id)->where('status', AdPublication::CREATING)->whereNull('ad_requested_at')
                        ->update(['status' => AdPublication::PROCESSING]);
                }
                $this->release(900);

                return false;
            }
            if ($e instanceof CreativeRejected && $e->rateLimited()) {
                $text = __('ads.errors.rate_limited');
            }
            $row->update(['status' => AdPublication::ERROR, 'error' => $text]);

            return true;
        }

        $row->update(['status' => AdPublication::DONE, 'external_ad_id' => $adId, 'error' => null]);
        $this->followUp($row, $account);

        return true;
    }

    /** A media ref the platform reports as failed or unknown is dropped from the file's cache so the next publish re-uploads. */
    private function ready(AdPlatformWriter $writer, AdPublication $row, MediaRef $media): bool
    {
        try {
            return $writer->mediaReady($row->account, $media);
        } catch (RateLimited $e) {
            throw $e;
        } catch (AdsApiException $e) {
            $this->forgetMedia($row, $media);
            throw $e;
        }
    }

    private function forgetMedia(AdPublication $row, MediaRef $media): void
    {
        $key = (string) $row->account->id;
        $file = $row->file->refresh();
        $all = (array) $file->platform_media;
        if (isset($all[$key]['id']) && (string) $all[$key]['id'] === $media->id) {
            unset($all[$key]);
            $file->update(['platform_media' => $all === [] ? null : $all]);
        }
    }

    /** Upload once per file and account: concurrent publications of the same file wait for the first upload. */
    private function upload(AdPlatformWriter $writer, AdPublication $row): MediaRef
    {
        $account = $row->account;
        $key = (string) $account->id;

        return Cache::lock("ads-media:{$row->ad_material_file_id}:{$account->id}", 900)->block(max(1, (int) config('crm.ads.publish_upload_wait', 60)), function () use ($writer, $row, $account, $key) {
            $file = $row->file->refresh();
            $cached = ((array) $file->platform_media)[$key] ?? null;
            if (is_array($cached) && isset($cached['kind'], $cached['id'])) {
                return new MediaRef((string) $cached['kind'], (string) $cached['id'], false);
            }
            $media = $writer->uploadMedia($account, $file);
            $file->update(['platform_media' => [...(array) $file->platform_media, $key => ['kind' => $media->kind, 'id' => $media->id]]]);

            return $media;
        });
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
