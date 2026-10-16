<?php

namespace App\Ads\Launch;

use App\Ads\Audit\AdsAudit;
use App\Ads\Control\Write\Canonical;
use App\Ads\Control\Write\Types\SetStatusType;
use App\Ads\Control\Write\WriteExecutor;
use App\Ads\Control\Write\WriteSwitch;
use App\Ads\Platforms\AdPlatform;
use App\Ads\Platforms\AdPlatformWriter;
use App\Ads\Platforms\DriverFactory;
use App\Ads\Platforms\SecretScrubber;
use App\Ads\Platforms\WriteGuard;
use App\Ads\Platforms\WriteRefused;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdDailyMetric;
use App\Models\AdLaunch;
use App\Models\AdPublication;
use App\Models\AdWriteAction;
use App\Models\AdWriteStep;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * Archives on the platform the PAUSED ads a launch made and then dropped (rejected, expired, returned by the manager,
 * withdrawn after a failed create): until now they were archived in the CRM only and piled up in Ads Manager.
 *
 * ARCHIVED, not DELETE: Meta keeps an archived ad readable (insights, audit, the CRM's own sync), an archived ad can
 * never be switched back on by mistake, and nothing is destroyed if the CRM ever picked the wrong ad. A system write,
 * not a human Stop/Run: one ad_write_actions row per ad (type archive_ad, actor system, its own permanent business
 * key so an ad is archived at most once), its steps, the kill switch, WriteGuard and the audit log; retried with
 * back-off; every outcome is written on the launch's history. Driven by the hourly ads:launch-sweep.
 *
 * Never an ad that ever ran: the launch was never approved or live, no Run of the ad was ever confirmed, the metrics
 * show no delivery, the local row is not ACTIVE, and a live read just before the call says PAUSED.
 */
final class PlatformArchive
{
    public const TYPE = 'archive_ad';

    public const SOURCE = 'launch_archive';

    /** Platform attempts in all; the delay after attempt n is BACKOFF_MINUTES[n-1]. */
    public const MAX_ATTEMPTS = 6;

    public const BACKOFF_MINUTES = [60, 180, 360, 720, 1440];

    /** Rows queued per pass (the first deploy backfills the old piles in a few hours). */
    public const QUEUE_LIMIT = 200;

    /** Set_status Run states that mean the ad was (or may have been) switched on. */
    private const RAN_STATES = [AdWriteAction::EXECUTING, AdWriteAction::SUCCEEDED, AdWriteAction::UNKNOWN, AdWriteAction::ROLLED_BACK, AdWriteAction::SUPERSEDED_BY_STOP];

    public function __construct(private readonly DriverFactory $drivers) {}

    /** @return array{archived: int, waiting: int, failed: int, skipped: int} */
    public function run(): array
    {
        $this->queue();
        $out = ['archived' => 0, 'waiting' => 0, 'failed' => 0, 'skipped' => 0];

        $due = AdWriteAction::query()->where('type', self::TYPE)->where('state', AdWriteAction::EXECUTING)
            ->whereNotNull('retry_at')->where('retry_at', '<=', now())->orderBy('id')->get();
        if (! WriteSwitch::enabled()) {
            // The kill switch holds the queue (an archive is not a Stop): the rows stay due, no attempt spent.
            AdWriteAction::query()->whereIn('id', $due->pluck('id')->all())->where('state', AdWriteAction::EXECUTING)
                ->update(['error_code' => WriteSwitch::CODE, 'updated_at' => now()]);
            $due = collect();
        }
        foreach ($due as $x) {
            $result = $this->attempt($x);
            if ($result !== null) {
                $out[$result]++;
            }
        }
        $out['waiting'] = AdWriteAction::query()->where('type', self::TYPE)->where('state', AdWriteAction::EXECUTING)->count();

        return $out;
    }

    /** One row per dropped CRM-made ad not queued yet. @return int rows queued */
    public function queue(): int
    {
        $pubs = AdPublication::query()->with(['launch', 'account'])
            ->whereNotNull('archived_at')->whereNotNull('external_ad_id')->whereNotNull('ad_launch_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ad_write_actions')->where('ad_write_actions.type', self::TYPE)
                ->whereColumn('ad_write_actions.ad_account_id', 'ad_publications.ad_account_id')
                ->whereColumn('ad_write_actions.target_external_id', 'ad_publications.external_ad_id'))
            ->orderBy('id')->limit(self::QUEUE_LIMIT)->get();

        $n = 0;
        foreach ($pubs as $p) {
            if ($p->account === null) {
                continue;
            }
            $ext = (string) $p->external_ad_id;
            $targetKey = SetStatusType::targetKey($p->account->id, 'ad', $ext);
            $diff = [['path' => 'status', 'before' => 'PAUSED', 'after' => 'ARCHIVED']];
            try {
                AdWriteAction::create([
                    'type' => self::TYPE, 'state' => AdWriteAction::EXECUTING, 'platform' => $p->account->platform,
                    'ad_account_id' => $p->account->id, 'account_name' => $p->account->name,
                    'target_level' => 'ad', 'target_external_id' => $ext, 'target_name' => mb_substr((string) $p->ad_name, 0, 500),
                    'target_key' => $targetKey, 'from_status' => 'PAUSED', 'to_status' => 'archived',
                    'params' => ['to' => 'archived', 'publication_id' => $p->id], 'diff' => $diff,
                    'diff_hash' => Canonical::hash(['type' => self::TYPE, 'account_id' => $p->account->id, 'external_id' => $ext, 'diff' => $diff]),
                    'source' => self::SOURCE, 'source_ref' => $p->launch?->public_id, 'actor_type' => 'system',
                    'idempotency_key' => 'archive:'.$targetKey,
                    // Never released: one archive per ad, ever (a twin insert fails on it).
                    'open_business_key' => 'archive:'.$targetKey,
                    'confirmed_at' => now(), 'executing_at' => now(), 'retry_at' => now(),
                ]);
                $n++;
            } catch (UniqueConstraintViolationException) {
                continue; // queued by a parallel pass
            }
        }

        return $n;
    }

    /** @return 'archived'|'failed'|'skipped'|null null = still waiting (a retry is scheduled) */
    private function attempt(AdWriteAction $x): ?string
    {
        // The claim: CAS on (state, retry_at, attempts), so a parallel pass never sends a second call for one attempt.
        $claimed = AdWriteAction::query()->whereKey($x->id)->where('state', AdWriteAction::EXECUTING)
            ->where('attempts', $x->attempts)->whereNotNull('retry_at')->where('retry_at', '<=', now())
            ->update(['retry_at' => null, 'updated_at' => now()]) === 1;
        if (! $claimed) {
            return null;
        }
        $x->refresh();
        $account = $x->account;
        $launch = AdLaunch::query()->where('public_id', (string) $x->source_ref)->first();

        if ($account === null) {
            return $this->end($x, $launch, AdWriteAction::FAILED, 'not_found', null);
        }
        if ($this->ran($x, $account, $launch)) {
            return $this->end($x, $launch, AdWriteAction::FAILED, 'ever_active', null);
        }

        AdWriteAction::query()->whereKey($x->id)->increment('attempts');
        $x->refresh();
        try {
            $writer = $this->drivers->writer(AdPlatform::from($account->platform));
            WriteGuard::check($account, $writer);
        } catch (WriteRefused $e) {
            return $this->retryOrFail($x, $launch, $e->reason, null);
        } catch (Throwable $e) {
            return $this->retryOrFail($x, $launch, 'platform_not_writable', SecretScrubber::scrub($e->getMessage()));
        }

        // The live read right before the call: only a PAUSED ad is archived; already archived or deleted is done.
        try {
            $live = strtoupper((string) $writer->readObject($account, 'ad', $x->target_external_id)->status);
        } catch (Throwable $e) {
            return $this->retryOrFail($x, $launch, 'read_failed', SecretScrubber::scrub($e->getMessage()));
        }
        if (in_array($live, ['ARCHIVED', 'DELETED'], true)) {
            return $this->succeed($x, $launch, ['already' => $live]);
        }
        if ($live !== 'PAUSED') {
            return $this->end($x, $launch, AdWriteAction::FAILED, 'ever_active', null, ['live_status' => $live]);
        }

        $step = $this->sendStep($x);
        try {
            $writer->setStatus($account, 'ad', $x->target_external_id, 'archived');
        } catch (WriteRefused $e) {
            $this->closeStep($step, AdWriteStep::FAILED, $e->reason, null);

            return $e->reason === 'archive_unsupported'
                ? $this->end($x, $launch, AdWriteAction::FAILED, $e->reason, null)
                : $this->retryOrFail($x, $launch, $e->reason, null);
        } catch (Throwable $e) {
            $message = SecretScrubber::scrub($e->getMessage());
            if ($this->readBackArchived($writer, $account, $x)) {
                $this->closeStep($step, AdWriteStep::SUCCEEDED, null, null, ['read_back' => true]);

                return $this->succeed($x, $launch, ['read_back' => true]);
            }
            $this->closeStep($step, AdWriteStep::FAILED, 'platform_error', $message);

            return $this->retryOrFail($x, $launch, 'platform_error', $message);
        }
        $this->closeStep($step, AdWriteStep::SUCCEEDED);

        return $this->succeed($x, $launch, []);
    }

    /** Was the ad ever switched on, by the launch, by a Run, or by delivery? */
    private function ran(AdWriteAction $x, AdAccount $account, ?AdLaunch $launch): bool
    {
        if ($launch !== null && ($launch->approved_at !== null || $launch->live_at !== null)) {
            return true;
        }
        $runs = AdWriteAction::query()->where('type', SetStatusType::TYPE)->where('target_key', $x->target_key)
            ->where('to_status', 'active')->whereIn('state', self::RAN_STATES)->exists();
        if ($runs) {
            return true;
        }
        $ad = Ad::query()->where('ad_account_id', $account->id)->where('external_id', $x->target_external_id)->first(['id', 'status', 'effective_status']);
        if ($ad === null) {
            return false;
        }
        if (strtoupper((string) $ad->status) === 'ACTIVE') {
            return true;
        }

        return AdDailyMetric::query()->where('ad_id', $ad->id)->where(fn ($q) => $q->where('impressions', '>', 0)->orWhere('spend', '>', 0))->exists();
    }

    private function readBackArchived(AdPlatformWriter $writer, AdAccount $account, AdWriteAction $x): bool
    {
        try {
            return in_array(strtoupper((string) $writer->readObject($account, 'ad', $x->target_external_id)->status), ['ARCHIVED', 'DELETED'], true);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return 'archived'|'failed'|null */
    private function retryOrFail(AdWriteAction $x, ?AdLaunch $launch, string $code, ?string $message): ?string
    {
        if ($x->attempts >= self::MAX_ATTEMPTS) {
            return $this->end($x, $launch, AdWriteAction::FAILED, $code, $message, ['deep_link' => WriteExecutor::deepLink($x), 'retry_exhausted' => true]);
        }
        $delay = self::BACKOFF_MINUTES[max(0, $x->attempts - 1)] ?? self::BACKOFF_MINUTES[count(self::BACKOFF_MINUTES) - 1];
        AdWriteAction::query()->whereKey($x->id)->where('state', AdWriteAction::EXECUTING)->update([
            'retry_at' => now()->addMinutes($delay), 'error_code' => $code,
            'error_message' => $message !== null ? mb_substr($message, 0, 2000) : null, 'updated_at' => now(),
        ]);

        return null;
    }

    /** @return 'archived' */
    private function succeed(AdWriteAction $x, ?AdLaunch $launch, array $outcome): string
    {
        $this->end($x, $launch, AdWriteAction::SUCCEEDED, null, null, $outcome);
        try {
            Ad::query()->where('ad_account_id', $x->ad_account_id)->where('external_id', $x->target_external_id)
                ->update(['status' => 'ARCHIVED', 'effective_status' => 'ARCHIVED', 'updated_at' => now()]);
        } catch (Throwable $e) {
            WriteExecutor::logUnexpected($e, $x);
        }

        return 'archived';
    }

    /**
     * The end state (CAS on executing; the business key stays so the ad is never queued again) and its line on the
     * launch history: platform_archived / platform_archive_skipped (it ran once) / platform_archive_failed.
     *
     * @return 'archived'|'failed'|'skipped'
     */
    private function end(AdWriteAction $x, ?AdLaunch $launch, string $state, ?string $code, ?string $message, array $outcome = []): string
    {
        $outcome = array_filter($outcome, fn ($v) => $v !== null);
        $changed = AdWriteAction::query()->whereKey($x->id)->where('state', AdWriteAction::EXECUTING)->update([
            'state' => $state, 'error_code' => $code, 'error_message' => $message !== null ? mb_substr($message, 0, 2000) : null,
            'retry_at' => null, 'finished_at' => now(), 'updated_at' => now(),
            'outcome' => $outcome !== [] ? json_encode(array_merge($x->outcome ?? [], $outcome)) : ($x->outcome !== null ? json_encode($x->outcome) : null),
        ]) === 1;
        $x->refresh();
        $result = match (true) {
            $state === AdWriteAction::SUCCEEDED => 'archived',
            $code === 'ever_active' => 'skipped',
            default => 'failed',
        };
        if (! $changed) {
            return $result;
        }
        AdsAudit::record('write.'.$state, $x, ['state' => AdWriteAction::EXECUTING], ['state' => $state],
            array_filter(['public_id' => $x->public_id, 'type' => self::TYPE, 'error_code' => $code]));
        if ($launch !== null) {
            AdsAudit::record(match ($result) {
                'archived' => 'launch.platform_archived', 'skipped' => 'launch.platform_archive_skipped', default => 'launch.platform_archive_failed',
            }, $launch, null, null, array_filter([
                'public_id' => $launch->public_id, 'action_id' => $x->public_id, 'ad' => $x->target_name, 'external_ad_id' => $x->target_external_id,
                'code' => $code, 'deep_link' => $outcome['deep_link'] ?? null,
            ]));
        }

        return $result;
    }

    private function sendStep(AdWriteAction $x): AdWriteStep
    {
        $step = AdWriteStep::create([
            'ad_write_action_id' => $x->id, 'seq' => ((int) AdWriteStep::where('ad_write_action_id', $x->id)->max('seq')) + 1,
            'op' => self::TYPE, 'level' => 'ad', 'target_external_id' => $x->target_external_id,
            'before' => ['status' => 'PAUSED'], 'after' => ['status' => 'ARCHIVED'], 'state' => AdWriteStep::PENDING, 'attempts' => 1,
        ]);
        $step->forceFill(['state' => AdWriteStep::SENT, 'request_sent_at' => now()])->save();

        return $step;
    }

    private function closeStep(AdWriteStep $step, string $state, ?string $code = null, ?string $message = null, array $meta = []): void
    {
        $step->forceFill([
            'state' => $state, 'error_code' => $code, 'error_message' => $message !== null ? mb_substr($message, 0, 2000) : null,
            'meta' => array_merge($step->meta ?? [], $meta) ?: null,
        ])->save();
    }
}
