<?php

namespace App\Ads\Sync;

use App\Ads\Audit\AdsAudit;
use App\Ads\Control\PublicationLinker;
use App\Ads\Launch\LaunchMonitor;
use App\Ads\Platforms\AdPlatform;
use App\Ads\Platforms\AdPlatformDriver;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\Data\AccountDailyTotal;
use App\Ads\Platforms\Data\AdRow;
use App\Ads\Platforms\Data\CreativeMedia;
use App\Ads\Platforms\Data\DailyAdMetric;
use App\Ads\Platforms\DriverFactory;
use App\Ads\Platforms\PreviewMarkup;
use App\Ads\Platforms\RateLimited;
use App\Ads\Platforms\SecretScrubber;
use App\Ads\Platforms\TokenInvalid;
use App\Ads\Reports\SpendSnapshots;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountDaily;
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

    /** A 'recent' run over at least this many requested days is the deep sync: it also sweeps statuses. */
    public const DEEP_DAYS = 30;

    /** error of the 'skipped' run written when another sync holds the account claim. */
    public const CLAIM_BUSY = 'Another sync of this account is running';

    /** Warning on the last backfill run when its claim expired and another sync took the account. */
    public const CLAIM_LOST = 'Backfill stopped: another sync took over the account';

    /** @var array<int, string> account id => claim key this instance holds (a backfill holds it across its chunks) */
    private array $claims = [];

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
            if ($e instanceof TokenInvalid) {
                ConnectionHealth::markNeedsReconnect($c, self::scrub($e->getMessage()));
                throw $e;
            }
            if ($e instanceof RateLimited) {
                $c->update(['last_error' => $e->getMessage()]);
            } else {
                ConnectionHealth::markError($c, $e->getMessage());
            }
            throw $e;
        }

        foreach ($infos as $i) {
            $account = AdAccount::firstOrNew(['platform' => $c->platform, 'external_id' => $i->externalId]);
            // An account stopped only because its connection was archived comes back when a connection finds it again (F-012).
            // One switched off by hand (no reason) stays off.
            $reactivate = $account->exists && ! $account->is_active && $account->deactivated_reason === 'connection_archived';
            $account->fill([
                'connection_id' => $c->id, 'name' => $i->name, 'currency' => $i->currency,
                'timezone' => $i->timezone, 'status' => $i->status, 'balance' => $i->balance,
            ]);
            if ($reactivate) {
                $account->is_active = true;
                $account->deactivated_reason = null;
            }
            $account->save();
            if ($reactivate) {
                AdsAudit::record('account.reactivated', $account, ['is_active' => false], ['is_active' => true], ['connection_id' => $c->id]);
            }
        }

        return count($infos);
    }

    /** ads + campaigns + adsets, then daily metrics for [from,to] (replacing the account's rows of those dates), then media. */
    public function syncAccount(AdAccount $a, CarbonImmutable $from, CarbonImmutable $to, string $kind = 'recent', bool $withAds = true, string $trigger = 'schedule', ?int $triggeredById = null, ?string $runKey = null, ?string $batchKey = null): AdsSyncRun
    {
        // Decided on the requested window, so a deep sync clamped by the history start still sweeps.
        $sweep = ($kind === 'recent' && (int) $from->diffInDays($to) + 1 >= self::DEEP_DAYS) || ($kind === 'backfill' && $withAds);
        $window = HistoryWindow::clamp($from, $to);
        if ($window === null) {
            return AdsSyncRun::create([
                'ad_account_id' => $a->id, 'platform' => $a->platform, 'kind' => $kind, 'status' => 'skipped',
                'trigger' => $trigger, 'triggered_by_id' => $triggeredById, 'error' => 'Window before history start',
                'from_date' => $from->toDateString(), 'to_date' => $to->toDateString(), 'started_at' => now(), 'finished_at' => now(),
            ]);
        }
        [$from, $to] = $window;

        $owned = $this->claim($a);
        if ($owned === null) {
            return $this->busyRun($a, $kind, $from, $to, $trigger, $triggeredById, $runKey, $batchKey);
        }

        try {
            $run = AdsSyncRun::create([
                'ad_account_id' => $a->id, 'platform' => $a->platform, 'kind' => $kind, 'status' => 'running',
                'trigger' => $trigger, 'triggered_by_id' => $triggeredById, 'run_key' => $runKey, 'batch_key' => $batchKey,
                'from_date' => $from->toDateString(), 'to_date' => $to->toDateString(), 'started_at' => now(),
            ]);

            try {
                return $this->runSync($a, $run, $from, $to, $withAds, $sweep);
            } catch (Throwable $e) {
                // Whatever escaped (bad driver config, DB error, media-phase bug) must not leave the run 'running'.
                if ($run->status === 'running') {
                    $run->update(['status' => 'error', 'error' => self::scrub($e->getMessage()), 'finished_at' => now()]);
                }
                throw $e;
            }
        } finally {
            if ($owned) {
                $this->unclaim($a);
            }
        }
    }

    /**
     * Takes the account claim unless this instance already holds it (a backfill around its chunks).
     *
     * @return bool|null true = taken here (release it), false = already held by this instance, null = another sync holds it
     */
    private function claim(AdAccount $a): ?bool
    {
        if (isset($this->claims[$a->id])) {
            return false;
        }
        $key = AccountSyncClaim::acquire($a, AccountSyncClaim::ttl());
        if ($key === null) {
            return null;
        }
        $this->claims[$a->id] = $key;

        return true;
    }

    private function unclaim(AdAccount $a): void
    {
        if (isset($this->claims[$a->id])) {
            AccountSyncClaim::release($a, $this->claims[$a->id]);
            unset($this->claims[$a->id]);
        }
    }

    /** The visible trace of a sync that found the account busy: one 'skipped' row per job (its run key), refreshed on each retry. */
    private function busyRun(AdAccount $a, string $kind, CarbonImmutable $from, CarbonImmutable $to, string $trigger, ?int $triggeredById, ?string $runKey, ?string $batchKey): AdsSyncRun
    {
        $existing = $runKey === null ? null : AdsSyncRun::where('ad_account_id', $a->id)->where('run_key', $runKey)
            ->where('status', 'skipped')->where('error', self::CLAIM_BUSY)->latest('id')->first();
        if ($existing !== null) {
            $existing->update(['started_at' => now(), 'finished_at' => now()]);

            return $existing;
        }

        return AdsSyncRun::create([
            'ad_account_id' => $a->id, 'platform' => $a->platform, 'kind' => $kind, 'status' => 'skipped',
            'trigger' => $trigger, 'triggered_by_id' => $triggeredById, 'run_key' => $runKey, 'batch_key' => $batchKey,
            'error' => self::CLAIM_BUSY, 'from_date' => $from->toDateString(), 'to_date' => $to->toDateString(),
            'started_at' => now(), 'finished_at' => now(),
        ]);
    }

    public static function isBusy(?AdsSyncRun $run): bool
    {
        return $run !== null && $run->status === 'skipped' && $run->error === self::CLAIM_BUSY;
    }

    private function runSync(AdAccount $a, AdsSyncRun $run, CarbonImmutable $from, CarbonImmutable $to, bool $withAds = true, bool $sweep = false): AdsSyncRun
    {
        $sweepWarning = null;
        $campaignWarning = null;
        $swept = false;
        $adListWarnings = [];
        $driver = $this->drivers->for(AdPlatform::from($a->platform));
        $driver->drainWarnings(); // only this run's warnings below

        try {
            // Quota admission once per run, before its first read; the later reads rely on the usage headers (A5).
            $driver->admit($a);
            // The ad list (full creative specs) is the heaviest Meta call: a backfill reads it once, on its first chunk.
            $adRows = $withAds ? $driver->ads($a) : [];
            // Any warning raised by ads() means the list is incomplete (cut by the high-usage paging stop): no sweep
            // this run, so nothing is judged GONE on a partial sighting.
            $adListWarnings = $driver->drainWarnings();
            if ($adListWarnings !== []) {
                $sweep = false;
            }
            $this->upsertAds($a, $adRows);
            $this->linkPublications($a);
            $metrics = $driver->dailyMetrics($a, $from, $to);
            [$control, $controlWarning] = $this->fetchControl($driver, $a, $from, $to);
            [$rows, $guard] = $this->replaceMetrics($a, $metrics, $from, $to, $control);
            $this->upsertAccountDaily($a, $control);
            if ($sweep && $withAds) {
                [$swept, $sweepWarning] = $this->sweepStatuses($a, $run, $driver);
            }
            if (! $withAds && $run->kind === 'recent') {
                $campaignWarning = $this->refreshCampaignStatuses($a, $driver);
            }
        } catch (AdsApiException $e) {
            $run->update(['status' => 'error', 'error' => self::scrub($e->getMessage()), 'finished_at' => now()]);
            if ($e instanceof RateLimited) {
                // Quota, not a broken connection: record on the run only and let the caller retry later.
                throw $e;
            }
            if ($e instanceof TokenInvalid && $a->connection) {
                ConnectionHealth::markNeedsReconnect($a->connection, self::scrub($e->getMessage()));

                return $run;
            }
            if ($a->connection) {
                ConnectionHealth::markError($a->connection, $e->getMessage());
            }

            return $run;
        }

        // Metrics are committed; a media failure must not turn the run into an error.
        $warnings = array_values(array_filter([...$adListWarnings, $controlWarning, $guard, $sweepWarning, $campaignWarning, ...$driver->drainWarnings()]));
        try {
            $this->fetchMedia($a, Ad::where('ad_account_id', $a->id)->whereNull('media_fetched_at')
                ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'unknown')) // minimal ads have no creative to fetch
                ->pluck('external_id')->all());
        } catch (AdsApiException $e) {
            $warnings[] = 'Creative media: '.self::scrub($e->getMessage());
        }

        $run->update([
            'status' => 'ok', 'ads_count' => count($adRows), 'rows_count' => $rows, 'error' => $warnings === [] ? null : implode(' | ', $warnings), 'finished_at' => now(),
            'swept_at' => $swept ? now() : null,
        ]);
        $a->update(['last_synced_at' => now()]);
        $a->connection?->update(['status' => 'connected', 'last_error' => null, 'last_synced_at' => now(), 'needs_reconnect_at' => null]);

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

    /**
     * Sync an account over $days days in 30-day chunks, newest first, holding the account claim across the chunks.
     * With a $batchKey (a queued backfill passes its run key), a chunk that already has an 'ok' run of that batch is not
     * asked again, so a backfill released by a rate limit resumes where it stopped (A5).
     */
    public function backfill(AdAccount $a, int $days, string $trigger = 'backfill', ?int $triggeredById = null, ?string $runKey = null, ?string $batchKey = null): ?AdsSyncRun
    {
        $run = null;
        $today = CarbonImmutable::now('Africa/Cairo')->startOfDay();
        $days = min($days, HistoryWindow::daysFromStart($today)); // nothing older than crm.ads.history_start
        if ($days <= 0) {
            return null;
        }
        $owned = $this->claim($a);
        if ($owned === null) {
            return $this->busyRun($a, 'backfill', $today->subDays($days - 1), $today, $trigger, $triggeredById, $runKey, $batchKey);
        }
        try {
            for ($offset = 0; $offset < $days; $offset += 30) {
                $to = $today->subDays($offset);
                $from = $today->subDays(min($offset + 29, $days - 1));
                $done = $batchKey === null ? null : AdsSyncRun::where('ad_account_id', $a->id)->where('kind', 'backfill')
                    ->where('batch_key', $batchKey)->where('status', 'ok')
                    ->whereDate('from_date', $from->toDateString())->whereDate('to_date', $to->toDateString())->latest('id')->first();
                if ($done !== null) {
                    $run = $done;

                    continue;
                }
                if (! AccountSyncClaim::extend($a, $this->claims[$a->id], AccountSyncClaim::ttl())) {
                    // The claim expired and another sync took it: stop here, never run two syncs of one account.
                    unset($this->claims[$a->id]);
                    $owned = false;
                    if ($run === null) {
                        // Lost before any chunk ran: leave a visible row.
                        $run = AdsSyncRun::create([
                            'ad_account_id' => $a->id, 'platform' => $a->platform, 'kind' => 'backfill', 'status' => 'skipped',
                            'trigger' => $trigger, 'triggered_by_id' => $triggeredById, 'run_key' => $runKey, 'batch_key' => $batchKey,
                            'error' => self::CLAIM_LOST, 'from_date' => $from->toDateString(), 'to_date' => $to->toDateString(),
                            'started_at' => now(), 'finished_at' => now(),
                        ]);
                    } else {
                        $run->update(['error' => trim(($run->error ? $run->error.' | ' : '').self::CLAIM_LOST)]);
                    }
                    break;
                }
                $run = $this->syncAccount($a, $from, $to, 'backfill', withAds: $offset === 0, trigger: $trigger, triggeredById: $triggeredById, runKey: $runKey, batchKey: $batchKey);
                if ($run->status === 'error') {
                    break;
                }
            }
        } finally {
            if ($owned) {
                $this->unclaim($a);
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
    /** Ads the CRM published are attached to their material once they are known locally; never fails the sync. */
    private function linkPublications(AdAccount $a): void
    {
        try {
            app(PublicationLinker::class)->link($a);
            app(LaunchMonitor::class)->afterSync($a); // launches follow their ads (T8 / T13 / T14)
        } catch (Throwable $e) {
            report($e);
        }
    }

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
                'raw' => json_encode($r->raw), 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        // Media columns (video/preview/permalink, media_fetched_at) stay out of the update list so a sync never wipes fetched media.
        $update = ['ad_campaign_id', 'ad_set_id', 'name', 'status', 'effective_status', 'type', 'headline', 'body', 'object_story_id', 'instagram_permalink_url', 'url_tags', 'carousel', 'created_time', 'raw', 'last_seen_at', 'updated_at'];
        foreach (array_chunk($payload, self::CHUNK) as $chunk) {
            Ad::upsert($chunk, ['ad_account_id', 'external_id'], $update);
        }
    }

    /**
     * Nightly status sweep (deep sync and backfill chunk 0 only; the full ad list was read in this run):
     *   - ads Meta lists as ARCHIVED/DELETED get that status and are marked seen;
     *   - campaigns get status, effective status, name, objective and last_seen_at from the campaigns list;
     *   - ads listed neither now nor by the previous completed sweep (seen, or created, before that sweep started)
     *     become GONE.
     * A failed list is a warning; without the ads list (null) the run is not a completed sweep and nothing becomes
     * GONE. A dead token or a rate limit stops the run.
     *
     * @return array{0: bool, 1: ?string} swept, warning
     */
    private function sweepStatuses(AdAccount $a, AdsSyncRun $run, AdPlatformDriver $driver): array
    {
        try {
            $lists = $driver->statuses($a);
        } catch (TokenInvalid|RateLimited $e) {
            throw $e;
        } catch (AdsApiException $e) {
            return [false, 'Status sweep: '.self::scrub($e->getMessage())];
        }
        $warning = ($lists['warnings'] ?? []) === [] ? null : 'Status sweep: '.self::scrub(implode('; ', $lists['warnings']));

        $now = now();
        // One UPDATE per (status, effective status) pair, ids in chunks.
        $groups = [];
        foreach ($lists['ads'] ?? [] as $externalId => $st) {
            $groups[($st['status'] ?? '')."\0".($st['effective_status'] ?? '')][] = (string) $externalId;
        }
        foreach ($groups as $pair => $ids) {
            [$status, $effective] = explode("\0", $pair);
            $values = array_filter(['status' => $status, 'effective_status' => $effective], fn ($v) => $v !== '') + ['last_seen_at' => $now];
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                Ad::where('ad_account_id', $a->id)->whereIn('external_id', $chunk)->update($values);
            }
        }
        foreach ($lists['campaigns'] ?? [] as $externalId => $c) {
            $values = array_filter([
                'name' => $c['name'] ?? null, 'status' => $c['status'] ?? null,
                'effective_status' => $c['effective_status'] ?? null, 'objective' => $c['objective'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');
            AdCampaign::where('ad_account_id', $a->id)->where('external_id', (string) $externalId)->update($values + ['last_seen_at' => $now]);
        }
        if (! is_array($lists['ads'] ?? null) || ! ($lists['ads_complete'] ?? true)) {
            return [false, $warning]; // no or partial ads list: the statuses are not all covered, so no GONE and no completed sweep
        }

        // Two consecutive completed sweeps without a sighting: this run and the previous one.
        $previous = AdsSyncRun::where('ad_account_id', $a->id)->where('id', '!=', $run->id)
            ->where('status', 'ok')->whereNotNull('swept_at')->latest('swept_at')->latest('id')->first();
        if ($previous?->started_at !== null) {
            $before = $previous->started_at;
            Ad::where('ad_account_id', $a->id)
                ->where(fn ($q) => $q->whereNull('effective_status')->orWhere('effective_status', '!=', 'GONE'))
                ->where(fn ($q) => $q->where('last_seen_at', '<', $before)
                    ->orWhere(fn ($n) => $n->whereNull('last_seen_at')->where('created_at', '<', $before)))
                ->update(['effective_status' => 'GONE']);
        }

        return [true, $warning];
    }

    /**
     * Hourly run without the ad list: the campaigns' own and effective statuses from one light call, so the
     * active-campaign scope of the screens is never more than about an hour stale. Only campaigns already stored are
     * updated (new ones arrive with the nightly ad list). A failed or rate-limited call is a warning; a dead token
     * stops the run.
     */
    private function refreshCampaignStatuses(AdAccount $a, AdPlatformDriver $driver): ?string
    {
        try {
            $list = $driver->campaignStatuses($a);
        } catch (TokenInvalid $e) {
            throw $e;
        } catch (AdsApiException $e) {
            // RateLimited included: the metrics are stored, so a refused refresh is a warning, not a retry.
            return 'Campaign statuses: '.self::scrub($e->getMessage());
        }
        $now = now();
        $groups = []; // one UPDATE per (status, effective status) pair
        foreach ($list ?? [] as $externalId => $st) {
            $groups[($st['status'] ?? '')."\0".($st['effective_status'] ?? '')][] = (string) $externalId;
        }
        foreach ($groups as $pair => $ids) {
            [$status, $effective] = explode("\0", $pair);
            $values = array_filter(['status' => $status, 'effective_status' => $effective], fn ($v) => $v !== '') + ['last_seen_at' => $now];
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                AdCampaign::where('ad_account_id', $a->id)->whereIn('external_id', $chunk)->update($values);
            }
        }

        return null;
    }

    /**
     * Account-level control totals for the run window. A failed control is a run warning only; a dead token or a
     * rate limit stops the run like any other call.
     *
     * @return array{0: list<AccountDailyTotal>|null, 1: ?string} null = no control (none on this platform, or it failed)
     */
    private function fetchControl(AdPlatformDriver $driver, AdAccount $a, CarbonImmutable $from, CarbonImmutable $to): array
    {
        try {
            $control = $driver->accountDaily($a, $from, $to);
        } catch (TokenInvalid|RateLimited $e) {
            throw $e;
        } catch (AdsApiException $e) {
            return [null, 'Account totals: '.self::scrub($e->getMessage())];
        }
        if ($control === null) {
            return [null, null];
        }

        $from = $from->toDateString();
        $to = $to->toDateString();

        return [array_values(array_filter($control, function (AccountDailyTotal $t) use ($from, $to) {
            $date = substr($t->date, 0, 10);

            return $date >= $from && $date <= $to && ! HistoryWindow::isBeforeStart($date);
        })), null];
    }

    /**
     * Insert keeps first_* = the first values seen; a later fetch moves only the latest columns and fetched_at, so a
     * platform restatement stays measurable.
     *
     * @param  list<AccountDailyTotal>|null  $control
     */
    private function upsertAccountDaily(AdAccount $a, ?array $control): void
    {
        if ($control === null || $control === []) {
            return;
        }
        $now = now()->toDateTimeString();
        $rows = [];
        foreach ($control as $t) {
            $rows[substr($t->date, 0, 10)] = [
                'ad_account_id' => $a->id, 'date' => substr($t->date, 0, 10),
                'spend' => round($t->spend, 2), 'impressions' => $t->impressions, 'purchases' => round($t->purchases, 2),
                'purchase_value' => round($t->purchaseValue, 2), 'currency' => $t->currency,
                'first_spend' => round($t->spend, 2), 'first_purchases' => round($t->purchases, 2),
                'first_purchase_value' => round($t->purchaseValue, 2), 'first_fetched_at' => $now, 'fetched_at' => $now,
            ];
        }
        foreach (array_chunk(array_values($rows), self::CHUNK) as $chunk) {
            AdAccountDaily::upsert($chunk, ['ad_account_id', 'date'], ['spend', 'impressions', 'purchases', 'purchase_value', 'currency', 'fetched_at']);
        }
        // Intraday history for «today vs usual by this hour» (S2 A3).
        app(SpendSnapshots::class)->record($a, $control);
    }

    /**
     * Upsert the payload, then delete stale rows (stored, not in the payload, any ad status) of a date only when the
     * payload of that date agrees with the account-level control total; otherwise keep them and say so (A1, F-050).
     * The control is the only guard. With a control that answered, a date it does not list totals 0. Without a
     * control (null: none on this platform, or the call failed) nothing stale is deleted.
     *
     * @param  list<DailyAdMetric>  $metrics
     * @param  list<AccountDailyTotal>|null  $control
     * @return array{0: int, 1: ?string} rows written and an optional warning
     */
    private function replaceMetrics(AdAccount $a, array $metrics, CarbonImmutable $from, CarbonImmutable $to, ?array $control = null): array
    {
        // Dedupe on (ad, date); the last row wins.
        $byKey = [];
        foreach ($metrics as $m) {
            if (HistoryWindow::isBeforeStart($m->date)) {
                continue; // defence in depth: a platform row before the history start is never stored
            }
            $byKey[$m->adExternalId.'|'.substr($m->date, 0, 10)] = $m;
        }

        $controlSpend = null;
        if ($control !== null) {
            $controlSpend = [];
            foreach ($control as $t) {
                $controlSpend[substr($t->date, 0, 10)] = $t->spend;
            }
        }

        return DB::transaction(function () use ($a, $byKey, $from, $to, $controlSpend) {
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
            $payloadSpend = [];
            foreach ($byKey as $m) {
                $adId = $adIds[$m->adExternalId];
                $date = substr($m->date, 0, 10);
                $keep[$adId.'|'.$date] = true;
                $payloadSpend[$date] = ($payloadSpend[$date] ?? 0.0) + $m->spend;
                $payload[] = [
                    'ad_id' => $adId, 'ad_account_id' => $a->id, 'date' => $date, 'spend' => $m->spend,
                    'impressions' => $m->impressions, 'clicks' => $m->clicks, 'link_clicks' => $m->linkClicks,
                    'msg_conversations' => $m->msgConversations, 'reach' => $m->reach,
                    'purchases' => $m->purchases, 'purchase_value' => $m->purchaseValue,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }

            // Platform corrections win, but only when the account total proves the payload of that date is complete.
            $stale = [];
            $guard = null;
            $windowFrom = max($from->toDateString(), HistoryWindow::start()->toDateString()); // never touch rows before the history start
            $windowRows = AdDailyMetric::where('ad_account_id', $a->id)->whereBetween('date', [$windowFrom, $to->toDateString()]);
            if ($byKey === [] && (clone $windowRows)->exists()) {
                // An empty payload over a populated window is more likely an API hiccup than a real wipe-out.
                $guard = 'Empty metrics payload: kept existing rows in the window';
            } else {
                $staleByDate = [];
                foreach ((clone $windowRows)->select(['id', 'ad_id', 'date'])->cursor() as $row) {
                    $date = $row->date->toDateString();
                    if (! isset($keep[$row->ad_id.'|'.$date])) {
                        $staleByDate[$date][] = $row->id;
                    }
                }
                ksort($staleByDate);
                $keptRows = 0;
                $keptDates = 0;
                $first = null;
                $tolerancePct = (float) config('crm.ads.control_tolerance_pct', 0.5);
                foreach ($staleByDate as $date => $ids) {
                    $payloadTotal = round($payloadSpend[$date] ?? 0.0, 2);
                    $accountTotal = $controlSpend === null ? null : (float) ($controlSpend[$date] ?? 0.0);
                    if ($accountTotal !== null && abs($payloadTotal - $accountTotal) <= max($tolerancePct / 100 * $accountTotal, 1.00)) {
                        array_push($stale, ...$ids);

                        continue;
                    }
                    $keptRows += count($ids);
                    $keptDates++;
                    $first ??= sprintf('%s: payload %.2f vs account %s', $date, $payloadTotal,
                        $accountTotal === null ? 'n/a' : number_format($accountTotal, 2, '.', ''));
                }
                // One summary line, however many dates were kept.
                $guard = $keptRows === 0 ? null : sprintf('Kept %d rows on %d dates (first: %s)', $keptRows, $keptDates, $first);
            }
            foreach (array_chunk($stale, self::CHUNK) as $ids) {
                AdDailyMetric::whereIn('id', $ids)->delete();
            }

            foreach (array_chunk($payload, self::CHUNK) as $chunk) {
                AdDailyMetric::upsert($chunk, ['ad_id', 'date'], ['ad_account_id', 'spend', 'impressions', 'clicks', 'link_clicks', 'msg_conversations', 'reach', 'purchases', 'purchase_value', 'updated_at']);
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
