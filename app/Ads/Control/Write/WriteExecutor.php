<?php

namespace App\Ads\Control\Write;

use App\Ads\Audit\AdsAudit;
use App\Ads\Control\AdWriteService;
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
use App\Models\AdAccount;
use App\Models\AdWriteAction;
use App\Models\AdWriteStep;
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
    ) {}

    /** The first attempt of a freshly claimed action (state executing). */
    public function execute(AdWriteAction $x): AdWriteAction
    {
        return $this->attempt($x);
    }

    /** One platform attempt of an action in `executing`; returns the action as it ends. */
    public function attempt(AdWriteAction $x): AdWriteAction
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

        $step = $this->sendStep($x);

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
            report($e);

            return $this->readBack($x, $step, $account, $writer, SecretScrubber::scrub($e->getMessage()));
        }

        $this->closeStep($step, AdWriteStep::SUCCEEDED);

        return $this->succeed($x, []);
    }

    /** Throttled: a Run fails with rate_limited (task 10 retries a Stop). */
    protected function rateLimited(AdWriteAction $x, AdWriteStep $step, RateLimited $e): AdWriteAction
    {
        $this->closeStep($step, AdWriteStep::FAILED, 'rate_limited', SecretScrubber::scrub($e->getMessage()));

        return $this->finish($x, AdWriteAction::FAILED, 'rate_limited', SecretScrubber::scrub($e->getMessage()),
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

    /** The read-back says the change did not land (task 10 retries a Stop). */
    protected function notApplied(AdWriteAction $x, string $message): AdWriteAction
    {
        return $this->finish($x, AdWriteAction::FAILED, 'not_applied', $message, ['read_back' => true]);
    }

    protected function succeed(AdWriteAction $x, array $outcome): AdWriteAction
    {
        $x = $this->finish($x, AdWriteAction::SUCCEEDED, null, null, $outcome);
        if ($x->state === AdWriteAction::SUCCEEDED) {
            $this->mirrorLocal($x);
        }

        return $x;
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
            $stored = is_string($stored) ? (json_decode($stored, true) ?: []) : [];
            $update['outcome'] = json_encode(array_merge($stored, $outcome));
        }
        $changed = AdWriteAction::whereKey($x->id)->where('state', AdWriteAction::EXECUTING)->update($update) === 1;

        $x->refresh();
        if ($changed) {
            AdsAudit::record('write.'.$state, $x, ['state' => AdWriteAction::EXECUTING], ['state' => $state],
                array_filter(['public_id' => $x->public_id, 'error_code' => $code], fn ($v) => $v !== null));
        }

        return $x;
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
                report($e);
            }
            AdWriteAction::whereKey($x->id)->update(['outcome' => json_encode(array_merge($x->outcome ?? [], [
                'local_status_error' => mb_substr(SecretScrubber::scrub($e->getMessage()), 0, 500),
            ]))]);
        }
    }
}
