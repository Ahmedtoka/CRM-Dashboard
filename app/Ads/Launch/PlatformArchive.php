<?php

namespace App\Ads\Launch;

use App\Ads\Audit\AdsAudit;
use App\Ads\Control\WritableAccounts;
use App\Ads\Control\Write\Canonical;
use App\Ads\Control\Write\Types\SetStatusType;
use App\Ads\Control\Write\WriteExecutor;
use App\Ads\Control\Write\WriteSwitch;
use App\Ads\Platforms\AdPlatform;
use App\Ads\Platforms\AdPlatformWriter;
use App\Ads\Platforms\DriverFactory;
use App\Ads\Platforms\SecretScrubber;
use App\Ads\Platforms\TokenInvalid;
use App\Ads\Platforms\WriteGuard;
use App\Ads\Platforms\WriteRefused;
use App\Ads\Sync\ConnectionHealth;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdDailyMetric;
use App\Models\AdLaunch;
use App\Models\AdPublication;
use App\Models\AdWriteAction;
use App\Models\AdWriteStep;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Archives on the platform the PAUSED ads a launch made and then dropped (rejected, expired, returned by the manager,
 * withdrawn after a failed create): until now they were archived in the CRM only and piled up in Ads Manager.
 *
 * ARCHIVED, not DELETE: Meta keeps an archived ad readable (insights, audit, the CRM's own sync), an archived ad can
 * never be switched back on by mistake, and nothing is destroyed if the CRM ever picked the wrong ad. A system write,
 * not a human Stop/Run: one ad_write_actions row per ad (type archive_ad, actor system, its own permanent business
 * key so an ad is archived at most once), its steps, the kill switch, the per-account write switch, WriteGuard and the
 * audit log; platform errors retried with back-off; every outcome is written on the launch's history. Driven by the
 * hourly ads:launch-sweep.
 *
 * Never an ad that may have run: the launch was never approved or live, no Run of the ad was ever confirmed, the
 * metrics show no delivery, the local row is not ACTIVE, and a live read just before the call says PAUSED.
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

    /** The sweep runs hourly at :05: a retry due a few minutes after a tick is taken at that tick. */
    public const DUE_GRACE_MINUTES = 5;

    /** How long a lasting refusal (switches, WriteGuard, reconnect) holds the row; no attempt is spent. */
    public const HOLD_MINUTES = 60;

    /** A claimed row older than this with no outcome died mid-attempt (crash): it goes back to the queue. */
    public const STUCK_MINUTES = 30;

    /** Final ends on purpose: never re-queued by --requeue-archives. */
    public const FINAL_CODES = ['ever_active', 'launch_approved', 'archive_unsupported', 'not_found'];

    /** Set_status Run states that mean the ad was (or may have been) switched on. */
    private const RAN_STATES = [AdWriteAction::EXECUTING, AdWriteAction::SUCCEEDED, AdWriteAction::UNKNOWN, AdWriteAction::ROLLED_BACK, AdWriteAction::SUPERSEDED_BY_STOP];

    public function __construct(private readonly DriverFactory $drivers) {}

    /** @return array{archived: int, waiting: int, failed: int, skipped: int} */
    public function run(): array
    {
        $this->queue();
        $this->recoverStuck();
        $out = ['archived' => 0, 'waiting' => 0, 'failed' => 0, 'skipped' => 0];

        $due = AdWriteAction::query()->where('type', self::TYPE)->where('state', AdWriteAction::EXECUTING)
            ->whereNotNull('retry_at')->where('retry_at', '<=', self::dueBy())->orderBy('id')->get();
        if (! WriteSwitch::enabled()) {
            // The kill switch holds the queue (an archive is not a Stop): the rows stay due, no attempt spent.
            AdWriteAction::query()->whereIn('id', $due->pluck('id')->all())->where('state', AdWriteAction::EXECUTING)
                ->update(['error_code' => WriteSwitch::CODE, 'updated_at' => now()]);
            $due = collect();
        }
        foreach ($due as $x) {
            try {
                $result = $this->attempt($x);
            } catch (Throwable $e) {
                // One bad row never stops the pass; recoverStuck() gives it back to the queue later.
                WriteExecutor::logUnexpected($e, $x);
                $result = null;
            }
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
                    // Never released: one archive row per ad, ever (a twin insert fails on it).
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

    /**
     * Gives failed archives another full round (after a reconnect or a long outage); never the final ends (the ad may
     * have run, the platform has no archive). `ads:launch-sweep --requeue-archives`.
     */
    public function requeueFailed(): int
    {
        $rows = AdWriteAction::query()->where('type', self::TYPE)->where('state', AdWriteAction::FAILED)
            ->where(fn ($q) => $q->whereNull('error_code')->orWhereNotIn('error_code', self::FINAL_CODES))->get()
            ->reject(fn (AdWriteAction $x) => isset($x->outcome['resolved_by_hand'])); // a human decision stays
        $n = 0;
        foreach ($rows as $x) {
            $outcome = array_diff_key($x->outcome ?? [], array_flip(['retry_exhausted', 'deep_link']));
            $changed = AdWriteAction::query()->whereKey($x->id)->where('state', AdWriteAction::FAILED)->update([
                'state' => AdWriteAction::EXECUTING, 'attempts' => 0, 'retry_at' => now(), 'finished_at' => null,
                'error_code' => null, 'error_message' => null, 'outcome' => $outcome !== [] ? json_encode($outcome) : null, 'updated_at' => now(),
            ]) === 1;
            if ($changed) {
                AdsAudit::record('write.requeued', $x, ['state' => AdWriteAction::FAILED], ['state' => AdWriteAction::EXECUTING],
                    ['public_id' => $x->public_id, 'type' => self::TYPE, 'error_code' => $x->error_code]);
                $n++;
            }
        }

        return $n;
    }

    /**
     * Archives held for more than $days (switches off, account not writable, connection to fix, sandbox): the Doctor
     * shows them so a lasting refusal never sits unseen.
     *
     * @return array<string, int> error_code => rows
     */
    public static function heldLong(int $days = 3): array
    {
        return AdWriteAction::query()->where('type', self::TYPE)->where('state', AdWriteAction::EXECUTING)
            ->whereNotNull('retry_at')->where('created_at', '<', now()->subDays($days))
            ->selectRaw("COALESCE(error_code, 'queued') as code, COUNT(*) as n")->groupBy('code')
            ->pluck('n', 'code')->map(fn ($n) => (int) $n)->all();
    }

    private static function dueBy(): Carbon
    {
        return now()->addMinutes(self::DUE_GRACE_MINUTES);
    }

    /** Rows claimed by a pass that died before an outcome: back to the queue. */
    private function recoverStuck(): int
    {
        return AdWriteAction::query()->where('type', self::TYPE)->where('state', AdWriteAction::EXECUTING)
            ->whereNull('retry_at')->where('updated_at', '<', now()->subMinutes(self::STUCK_MINUTES))
            ->update(['retry_at' => now(), 'updated_at' => now()]);
    }

    /** @return 'archived'|'failed'|'skipped'|null null = still waiting (held, or a retry is scheduled) */
    private function attempt(AdWriteAction $x): ?string
    {
        // The claim: CAS on (state, retry_at, attempts), so a parallel pass never sends a second call for one attempt.
        $claimed = AdWriteAction::query()->whereKey($x->id)->where('state', AdWriteAction::EXECUTING)
            ->where('attempts', $x->attempts)->whereNotNull('retry_at')->where('retry_at', '<=', self::dueBy())
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
        $ran = $this->ran($x, $account, $launch);
        if ($ran !== null) {
            return $this->end($x, $launch, AdWriteAction::FAILED, $ran, null);
        }

        // Lasting refusals hold the row (no attempt spent, never a final failure): the kill switch (re-read for each
        // row), the owner's per-account write switch, and WriteGuard (reconnect, read only, sandbox, fake in production).
        if (! WriteSwitch::enabled()) {
            return $this->hold($x, WriteSwitch::CODE);
        }
        if (! WritableAccounts::allows($account)) {
            return $this->hold($x, 'account_not_writable');
        }
        try {
            $writer = $this->drivers->writer(AdPlatform::from($account->platform));
            WriteGuard::check($account, $writer);
        } catch (WriteRefused $e) {
            return $this->hold($x, $e->reason);
        } catch (Throwable $e) {
            WriteExecutor::logUnexpected($e, $x); // never a silent hold: a code or config error shows in the log

            return $this->hold($x, 'platform_not_writable');
        }

        AdWriteAction::query()->whereKey($x->id)->increment('attempts');
        $x->refresh();

        // The live read right before the call: only a PAUSED ad is archived; already archived or deleted is done.
        try {
            $live = strtoupper((string) $writer->readObject($account, 'ad', $x->target_external_id)->status);
        } catch (TokenInvalid $e) {
            $this->reconnect($account, $e);

            return $this->hold($x, 'connection_needs_reconnect', true);
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
        } catch (TokenInvalid $e) {
            $this->closeStep($step, AdWriteStep::FAILED, 'connection_needs_reconnect', SecretScrubber::scrub($e->getMessage()));
            $this->reconnect($account, $e);

            return $this->hold($x, 'connection_needs_reconnect', true);
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

    /**
     * Why the ad may have run, or null: the launch was approved or went live (launch_approved), or a Run of the ad was
     * confirmed, the local row is ACTIVE, or the metrics show delivery (ever_active). Either is a final skip.
     */
    private function ran(AdWriteAction $x, AdAccount $account, ?AdLaunch $launch): ?string
    {
        if ($launch !== null && ($launch->approved_at !== null || $launch->live_at !== null)) {
            return 'launch_approved';
        }
        $runs = AdWriteAction::query()->where('type', SetStatusType::TYPE)->where('target_key', $x->target_key)
            ->where('to_status', 'active')->whereIn('state', self::RAN_STATES)->exists();
        if ($runs) {
            return 'ever_active';
        }
        $ad = Ad::query()->where('ad_account_id', $account->id)->where('external_id', $x->target_external_id)->first(['id', 'status', 'effective_status']);
        if ($ad === null) {
            return null;
        }
        if (strtoupper((string) $ad->status) === 'ACTIVE') {
            return 'ever_active';
        }

        return AdDailyMetric::query()->where('ad_id', $ad->id)->where(fn ($q) => $q->where('impressions', '>', 0)->orWhere('spend', '>', 0))->exists()
            ? 'ever_active' : null;
    }

    /**
     * Postpones the row without an end; $refund gives back the attempt already counted for this pass.
     *
     * @return null still waiting
     */
    private function hold(AdWriteAction $x, string $code, bool $refund = false): ?string
    {
        AdWriteAction::query()->whereKey($x->id)->where('state', AdWriteAction::EXECUTING)->update(array_merge([
            'retry_at' => now()->addMinutes(self::HOLD_MINUTES - self::DUE_GRACE_MINUTES), 'error_code' => $code, 'updated_at' => now(),
        ], $refund && $x->attempts > 0 ? ['attempts' => $x->attempts - 1] : []));

        return null;
    }

    private function reconnect(AdAccount $account, Throwable $e): void
    {
        if ($account->connection !== null) {
            ConnectionHealth::markNeedsReconnect($account->connection, SecretScrubber::scrub($e->getMessage()));
        }
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
            // Minus the grace so the hourly tick after the delay takes it (not the one after that).
            'retry_at' => now()->addMinutes($delay - self::DUE_GRACE_MINUTES), 'error_code' => $code,
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
     * launch history: platform_archived / platform_archive_skipped (it may have run) / platform_archive_failed.
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
            in_array($code, ['ever_active', 'launch_approved'], true) => 'skipped',
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
