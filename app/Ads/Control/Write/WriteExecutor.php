<?php

namespace App\Ads\Control\Write;

use App\Ads\Audit\AdsAudit;
use App\Ads\Control\AdWriteService;
use App\Ads\Control\Write\Jobs\RetryStopWrite;
use App\Ads\Control\Write\Types\SetStatusType;
use App\Ads\Platforms\AdPlatform;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\DriverFactory;
use App\Ads\Platforms\MissingPermission;
use App\Ads\Platforms\PlatformUnreachable;
use App\Ads\Platforms\RateLimited;
use App\Ads\Platforms\SecretScrubber;
use App\Ads\Platforms\TokenInvalid;
use App\Ads\Platforms\WriteGuard;
use App\Ads\Platforms\WriteRefused;
use App\Ads\Sync\ConnectionHealth;
use App\Inbox\UserNotifier;
use App\Models\AdAccount;
use App\Models\AdWriteAction;
use App\Models\AdWriteStep;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one confirmed action against the platform (write-api 4): exactly one platform call per attempt, recorded as a
 * step that is committed as `sent` BEFORE the call, then classified. A transport failure is never guessed: the object is
 * read back, and when that fails too the action stays `unknown` (a Run keeps its key, 2.1 rule 2).
 */
class WriteExecutor
{
    public function __construct(
        private readonly WritePolicy $policy,
        private readonly DriverFactory $drivers,
        private readonly SetStatusType $type,
        private readonly WriteLimits $limits,
    ) {}

    /**
     * The first attempt of a freshly claimed action (state executing). A noop Run (the Run guard read the target as
     * already ACTIVE) ends succeeded with outcome.noop and no platform call; it still counts as a confirmed activation.
     */
    public function execute(AdWriteAction $x, bool $noop = false): AdWriteAction
    {
        if ($noop && ! $x->isStop()) {
            return $this->succeed($x, ['noop' => true]);
        }

        return $this->attempt($x);
    }

    /** One platform attempt of an action in `executing`; returns the action as it ends. */
    public function attempt(AdWriteAction $x, array $stepMeta = []): AdWriteAction
    {
        $account = $x->account;
        $confirmer = $x->confirmer;

        try {
            if ($account === null || $confirmer === null) {
                throw WriteDenied::make('out_of_scope');
            }
            $this->policy->authorize($confirmer, $account, $x->target_level, (string) $x->to_status, 'execute');
            $writer = $this->drivers->writer(AdPlatform::from($account->platform));
            WriteGuard::check($account, $writer);
        } catch (WriteDenied $e) {
            return $this->finish($x, AdWriteAction::FAILED, $e->errorCode, null, []);
        } catch (WriteRefused $e) {
            return $this->finish($x, AdWriteAction::FAILED, $e->reason, null, []);
        } catch (AdsApiException $e) {
            return $this->finish($x, AdWriteAction::FAILED, 'platform_not_writable', SecretScrubber::scrub($e->getMessage()), []);
        }

        $step = $this->sendStep($x, $stepMeta);

        try {
            $writer->setStatus($account, $x->target_level, $x->target_external_id, (string) $x->to_status);
        } catch (WriteRefused $e) {
            return $this->failStep($x, $step, $e->reason, null);
        } catch (TokenInvalid $e) {
            $message = SecretScrubber::scrub($e->getMessage());
            if ($account->connection !== null) {
                ConnectionHealth::markNeedsReconnect($account->connection, $message);
            }

            return $this->failStep($x, $step, 'connection_needs_reconnect', $message);
        } catch (MissingPermission $e) {
            return $this->failStep($x, $step, 'permission_missing', SecretScrubber::scrub($e->getMessage()));
        } catch (RateLimited $e) {
            return $this->rateLimited($x, $step, $e);
        } catch (PlatformUnreachable $e) {
            return $this->readBack($x, $step, $account, $writer, SecretScrubber::scrub($e->getMessage()));
        } catch (AdsApiException $e) {
            // ASSUMPTION: a platform error object (4xx) means the change was not applied.
            return $this->failStep($x, $step, 'platform_rejected', SecretScrubber::scrub($e->getMessage()));
        } catch (Throwable $e) {
            self::logUnexpected($e, $x);

            return $this->readBack($x, $step, $account, $writer, SecretScrubber::scrub($e->getMessage()));
        }

        $this->closeStep($step, AdWriteStep::SUCCEEDED);

        return $this->succeed($x, []);
    }

    /** Throttled: a Run fails with rate_limited; a confirmed Stop is retried (2.1 rule 6). */
    protected function rateLimited(AdWriteAction $x, AdWriteStep $step, RateLimited $e): AdWriteAction
    {
        $message = SecretScrubber::scrub($e->getMessage());
        $this->closeStep($step, AdWriteStep::FAILED, 'rate_limited', $message, array_filter(['retry_after' => $e->retryAfterSeconds], fn ($v) => $v !== null));
        if ($x->isStop()) {
            return $this->retryOrFail($x, 'rate_limited', $message, $e->retryAfterSeconds);
        }

        return $this->finish($x, AdWriteAction::FAILED, 'rate_limited', $message,
            array_filter(['retry_after' => $e->retryAfterSeconds], fn ($v) => $v !== null));
    }

    /** The answer was lost: read the object back and decide from what the platform holds now. */
    protected function readBack(AdWriteAction $x, AdWriteStep $step, AdAccount $account, mixed $writer, string $message): AdWriteAction
    {
        try {
            $live = $writer->readObject($account, $x->target_level, $x->target_external_id);
        } catch (Throwable $e) {
            $this->closeStep($step, AdWriteStep::UNKNOWN, 'unknown_outcome', $message, ['read_back_error' => SecretScrubber::scrub($e->getMessage())]);

            return $this->finish($x, AdWriteAction::UNKNOWN, 'unknown_outcome', $message, ['read_back' => false]);
        }

        if (AdWriteService::statusKind($live->status) === $x->to_status) {
            $this->closeStep($step, AdWriteStep::SUCCEEDED, null, null, ['read_back' => true]);

            return $this->succeed($x, ['read_back' => true]);
        }

        $this->closeStep($step, AdWriteStep::FAILED, 'not_applied', $message, ['read_back' => true, 'read_status' => $live->status]);

        return $this->notApplied($x, $message);
    }

    /** The read-back says the change did not land: a Run fails, a confirmed Stop is retried (2.1 rule 6). */
    protected function notApplied(AdWriteAction $x, string $message): AdWriteAction
    {
        if ($x->isStop()) {
            return $this->retryOrFail($x, 'not_applied', $message, null);
        }

        return $this->finish($x, AdWriteAction::FAILED, 'not_applied', $message, ['read_back' => true]);
    }

    /**
     * Bounded retry of a confirmed Stop (2.1 rule 6, R-05): up to stop_retry_attempts attempts in all, at least
     * stop_retry_seconds apart and never before Meta's regain time. The action stays executing; the claim of the retry
     * is the attempts counter (CAS in RetryStopWrite), never the cache. Past the bound: failed, the Ads Manager link,
     * and a notice to the Ads-authority holders. No new decision is taken: it is the same confirmed intent.
     */
    protected function retryOrFail(AdWriteAction $x, string $code, string $message, ?int $regainSeconds): AdWriteAction
    {
        $x->refresh();
        $delay = max((int) config('crm.ads.write.stop_retry_seconds', 60), (int) $regainSeconds);
        $canRetry = $x->attempts < (int) config('crm.ads.write.stop_retry_attempts', 3)
            && $delay <= (int) config('crm.ads.write.stop_retry_max_wait_seconds', 1800);

        if ($canRetry) {
            $retryAt = now()->addSeconds($delay);
            $scheduled = AdWriteAction::whereKey($x->id)->where('state', AdWriteAction::EXECUTING)
                ->update(['retry_at' => $retryAt, 'error_code' => $code, 'error_message' => mb_substr($message, 0, 2000), 'updated_at' => now(),
                    'outcome' => json_encode(array_merge($x->outcome ?? [], ['retry_scheduled' => true]))]) === 1;
            $x->refresh();
            if ($scheduled) {
                AdsAudit::record('write.retry_scheduled', $x, null, ['retry_at' => $retryAt->toIso8601String()],
                    ['public_id' => $x->public_id, 'attempts' => $x->attempts, 'delay_seconds' => $delay, 'error_code' => $code]);
                RetryStopWrite::dispatch($x->id, (int) $x->attempts)
                    ->onQueue((string) config('crm.ads.write.retry_queue', 'commerce'))
                    ->delay($retryAt);
            }

            return $x;
        }

        // finish() sends the "open in Ads Manager" notice (retry_exhausted marks the bound).
        return $this->finish($x, AdWriteAction::FAILED, $code, $message,
            array_filter(['deep_link' => self::deepLink($x), 'retry_after' => $regainSeconds, 'retry_exhausted' => true], fn ($v) => $v !== null));
    }

    /**
     * A confirmed Stop that nobody is watching any more (a scheduled retry, a re-apply, the bound reached) ended in a
     * terminal failure: tell every Ads-authority holder, with the Ads Manager link (2.1 rule 6).
     */
    protected function noticeStopFailed(AdWriteAction $x): void
    {
        $link = $x->outcome['deep_link'] ?? self::deepLink($x);
        if ($link !== null && ! isset($x->outcome['deep_link'])) {
            AdWriteAction::whereKey($x->id)->update(['outcome' => json_encode(array_merge($x->outcome ?? [], ['deep_link' => $link]))]);
            $x->refresh();
        }
        app(UserNotifier::class)->notifyAdsAuthority('ads.stop_failed', array_filter([
            'action_id' => $x->public_id, 'name' => $x->target_name, 'account' => $x->account_name, 'level' => $x->target_level,
            'error_code' => $x->error_code, 'deep_link' => $link, 'link' => '/ads/actions',
        ], fn ($v) => $v !== null));
    }

    /** Unexpected exception: logged with a scrubbed message only (never the raw text, which may carry a token). */
    public static function logUnexpected(Throwable $e, ?AdWriteAction $x = null): void
    {
        Log::error('ads write: unexpected error', array_filter([
            'class' => $e::class,
            'message' => SecretScrubber::scrub($e->getMessage()),
            'action' => $x?->public_id,
        ], fn ($v) => $v !== null));
    }

    /**
     * "Open in Ads Manager to pause" (Meta only). ASSUMPTION: the selected_<level>_ids query of the Ads Manager URL.
     */
    public static function deepLink(AdWriteAction $x): ?string
    {
        if ($x->platform !== 'meta') {
            return null;
        }
        $act = preg_replace('/^act_/', '', (string) ($x->account?->external_id ?? ''));
        if ($act === '' || ! ctype_digit($act)) {
            return null;
        }
        [$page, $param] = match ($x->target_level) {
            'campaign' => ['campaigns', 'selected_campaign_ids'],
            'adset' => ['adsets', 'selected_adset_ids'],
            default => ['ads', 'selected_ad_ids'],
        };

        return 'https://adsmanager.facebook.com/adsmanager/manage/'.$page.'?act='.$act.'&'.$param.'='.rawurlencode($x->target_external_id);
    }

    protected function succeed(AdWriteAction $x, array $outcome): AdWriteAction
    {
        $x = $this->finish($x, AdWriteAction::SUCCEEDED, null, null, $outcome);
        if ($x->state === AdWriteAction::SUCCEEDED) {
            $this->mirrorLocal($x);
            if ($x->isStop()) {
                $this->restartLock($x);
                $this->supersedeRunsBy($x);
            }
            if ($x->rollback_of_id !== null) {
                $this->markRolledBack($x);
            }
        }

        return $x->refresh();
    }

    /**
     * A succeeded Stop confirmed by an Ads-authority holder locks other users' Runs on the target for restart_lock_days
     * (B3; RunGuard::restartLock enforces it). 0 days = no lock.
     */
    protected function restartLock(AdWriteAction $stop): void
    {
        $confirmer = $stop->confirmer;
        if ($confirmer === null || ! $confirmer->hasAdsAuthority()) {
            return;
        }
        $days = $this->limits->for($confirmer, $stop->account)['restart_lock_days'];
        if ($days <= 0) {
            return;
        }
        $until = now()->addDays($days);
        AdWriteAction::whereKey($stop->id)->update(['restart_lock_until' => $until]);
        $stop->restart_lock_until = $until;
    }

    /** A succeeded rollback: the original action becomes rolled_back (CAS on succeeded). */
    protected function markRolledBack(AdWriteAction $rollback): void
    {
        $original = AdWriteAction::find($rollback->rollback_of_id);
        if ($original === null) {
            return;
        }
        $changed = AdWriteAction::whereKey($original->id)->where('state', AdWriteAction::SUCCEEDED)->update([
            'state' => AdWriteAction::ROLLED_BACK, 'rolled_back_by_id' => $rollback->id, 'updated_at' => now(),
        ]) === 1;
        if ($changed) {
            $original->refresh();
            AdsAudit::record('write.rolled_back', $original, ['state' => AdWriteAction::SUCCEEDED], ['state' => AdWriteAction::ROLLED_BACK],
                ['public_id' => $original->public_id, 'rolled_back_by' => $rollback->public_id]);
        }
    }

    /** Stop beats Run (2.1 rule 4): the Runs on the target still executing or unknown are superseded_by_stop. */
    protected function supersedeRunsBy(AdWriteAction $stop): void
    {
        $ids = AdWriteAction::where('target_key', $stop->target_key)->where('to_status', 'active')
            ->whereIn('state', AdWriteAction::OPEN)->pluck('id')->all();
        if ($ids === []) {
            return;
        }
        AdWriteAction::whereIn('id', $ids)->whereIn('state', AdWriteAction::OPEN)->update([
            'state' => AdWriteAction::SUPERSEDED_BY_STOP, 'open_business_key' => null, 'superseded_by_id' => $stop->id,
            'finished_at' => now(), 'retry_at' => null, 'updated_at' => now(),
        ]);
        foreach (AdWriteAction::whereIn('id', $ids)->where('superseded_by_id', $stop->id)->get() as $run) {
            AdsAudit::record('write.superseded_by_stop', $run, null, ['state' => AdWriteAction::SUPERSEDED_BY_STOP],
                ['public_id' => $run->public_id, 'superseded_by' => $stop->public_id]);
        }
    }

    /**
     * The Run landed after the Stop: re-apply the same confirmed Stop (a new step on the Stop, bounded by the same retry
     * rule; part of the human's confirmed intent, never a new decision). The Stop goes back to executing with a fresh
     * attempt budget while the platform holds the Run's change; its steps keep the whole history.
     */
    protected function reapplyStop(AdWriteAction $run, string $runOutcome): void
    {
        $note = $runOutcome === AdWriteAction::SUCCEEDED ? ['landed_after_stop' => true] : ['maybe_landed_after_stop' => $runOutcome];
        AdWriteAction::whereKey($run->id)->update(['outcome' => json_encode(array_merge($run->outcome ?? [], $note))]);
        $stop = $run->superseded_by_id !== null ? AdWriteAction::find($run->superseded_by_id) : null;
        if ($stop === null) {
            return;
        }
        $reopened = AdWriteAction::whereKey($stop->id)->where('state', AdWriteAction::SUCCEEDED)->update([
            'state' => AdWriteAction::EXECUTING, 'finished_at' => null, 'retry_at' => null, 'attempts' => 0, 'updated_at' => now(),
            'outcome' => json_encode(array_merge($stop->outcome ?? [], ['reapply_of' => $run->public_id])),
        ]) === 1;
        if (! $reopened) {
            return; // already being re-applied, rolled back, or no longer the Stop that won
        }
        $stop->refresh();
        AdsAudit::record('write.stop_reapplied', $stop, ['state' => AdWriteAction::SUCCEEDED], ['state' => AdWriteAction::EXECUTING],
            ['public_id' => $stop->public_id, 'reapply_of' => $run->public_id]);
        $this->attempt($stop, ['reapply_of' => $run->public_id]);
    }

    protected function failStep(AdWriteAction $x, AdWriteStep $step, string $code, ?string $message): AdWriteAction
    {
        $this->closeStep($step, AdWriteStep::FAILED, $code, $message);

        return $this->finish($x, AdWriteAction::FAILED, $code, $message, $message !== null ? ['platform_message' => $message] : []);
    }

    /** A new step, committed as `sent` before the platform call; attempts counts the calls. */
    protected function sendStep(AdWriteAction $x, array $meta = []): AdWriteStep
    {
        $seq = ((int) AdWriteStep::where('ad_write_action_id', $x->id)->max('seq')) + 1;
        $step = AdWriteStep::create([
            'ad_write_action_id' => $x->id,
            'seq' => $seq,
            'op' => SetStatusType::TYPE,
            'level' => $x->target_level,
            'target_external_id' => $x->target_external_id,
            'before' => ['status' => $x->from_status],
            'after' => ['status' => $x->to_status === 'active' ? 'ACTIVE' : 'PAUSED'],
            'state' => AdWriteStep::PENDING,
            'attempts' => 1,
            'meta' => $meta ?: null,
        ]);
        $step->forceFill(['state' => AdWriteStep::SENT, 'request_sent_at' => now()])->save();
        AdWriteAction::whereKey($x->id)->increment('attempts');

        return $step;
    }

    protected function closeStep(AdWriteStep $step, string $state, ?string $code = null, ?string $message = null, array $meta = []): void
    {
        $step->forceFill([
            'state' => $state,
            'error_code' => $code,
            'error_message' => $message !== null ? mb_substr($message, 0, 2000) : null,
            'meta' => array_merge($step->meta ?? [], $meta) ?: null,
        ])->save();
    }

    /**
     * Moves an executing action to its end state (CAS on state = executing). A terminal state frees the Run key;
     * `unknown` keeps it (2.1 rule 2). Returns the action as stored (another writer may have moved it first).
     */
    public function finish(AdWriteAction $x, string $state, ?string $code, ?string $message, array $outcome): AdWriteAction
    {
        $terminal = in_array($state, AdWriteAction::TERMINAL, true);
        $update = [
            'state' => $state,
            'error_code' => $code,
            'error_message' => $message !== null ? mb_substr($message, 0, 2000) : null,
            'retry_at' => null,
            'updated_at' => now(),
        ];
        if ($terminal) {
            $update['finished_at'] = now();
            $update['open_business_key'] = null;
        }
        if ($outcome !== []) {
            $stored = AdWriteAction::whereKey($x->id)->value('outcome');
            $stored = is_array($stored) ? $stored : (is_string($stored) ? (json_decode($stored, true) ?: []) : []);
            $update['outcome'] = json_encode(array_merge($stored, $outcome));
        }
        $changed = AdWriteAction::whereKey($x->id)->where('state', AdWriteAction::EXECUTING)->update($update) === 1;

        $x->refresh();
        if ($changed) {
            AdsAudit::record('write.'.$state, $x, ['state' => AdWriteAction::EXECUTING], ['state' => $state],
                array_filter(['public_id' => $x->public_id, 'error_code' => $code], fn ($v) => $v !== null));
            if ($x->isStop() && $state === AdWriteAction::FAILED && array_intersect_key($x->outcome ?? [], array_flip(['retry_scheduled', 'retry_exhausted', 'reapply_of'])) !== []) {
                $this->noticeStopFailed($x);
            }
        } elseif (! $x->isStop() && $x->state === AdWriteAction::SUPERSEDED_BY_STOP && self::maybeLanded($state, $code)) {
            // A Stop won while this Run's call was in flight (2.1 rule 4). Unless the call is known not to have changed
            // anything, re-apply the confirmed Stop: pausing twice is harmless, a Run left ACTIVE is not.
            $this->reapplyStop($x, $state);
        }

        return $x;
    }

    /** Outcomes after which a Run's call may have reached the platform (succeeded, unknown, read back not applied). */
    public static function maybeLanded(string $state, ?string $code): bool
    {
        return $state === AdWriteAction::SUCCEEDED || $state === AdWriteAction::UNKNOWN || $code === 'not_applied';
    }

    /** Mirror the new status on the local row like slice 1; a local failure is noted, never undoes the action. */
    protected function mirrorLocal(AdWriteAction $x): void
    {
        try {
            $account = $x->account;
            if ($account === null) {
                return;
            }
            $this->type->target($account, $x->target_level, $x->target_external_id)
                ->forceFill(['status' => $x->to_status === 'active' ? 'ACTIVE' : 'PAUSED'])->save();
        } catch (Throwable $e) {
            if (! $e instanceof WriteDenied) {
                self::logUnexpected($e, $x);
            }
            AdWriteAction::whereKey($x->id)->update(['outcome' => json_encode(array_merge($x->outcome ?? [], [
                'local_status_error' => mb_substr(SecretScrubber::scrub($e->getMessage()), 0, 500),
            ]))]);
        }
    }
}
