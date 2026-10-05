<?php

namespace App\Ads\Control\Write;

use App\Ads\Control\Write\Types\SetStatusType;
use App\Ads\Platforms\AdPlatform;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\Data\ObjectState;
use App\Ads\Platforms\DriverFactory;
use App\Ads\Platforms\RateLimited;
use App\Models\AdAccount;
use App\Models\AdWriteAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The Run guard (B3): a Run acts on the platform's truth, not on a local row that can be an hour old. Stop never reaches
 * this class (2.1 rule 5: no pre-read, no cap, no lock; a redundant pause is harmless).
 *
 * - Live pre-read (write-api 3): at propose the object is read and its live status goes into the diff and `expected`;
 *   at confirm a read younger than crm.ads.write.preread_fresh_seconds is reused, else the object is read again. Live
 *   ACTIVE → noop (no platform call); anything but PAUSED, or not the expected status → superseded (409).
 * - A read that fails is a refusal (fail closed): 422 budget_unreadable, or 429 rate_limited when throttled.
 */
class RunGuard
{
    /** The only live statuses a Run may act on (anything else, e.g. ARCHIVED, DELETED, IN_PROCESS, is refused). */
    private const RUNNABLE = 'PAUSED';

    public function __construct(
        private readonly DriverFactory $drivers,
    ) {}

    /**
     * Called for a Run at propose, after the policy and the target lookup.
     *
     * @return array{0: ObjectState, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>} [live read, limits checked, notes]
     *
     * @throws WriteDenied
     */
    public function atPropose(User $u, AdAccount $a, Model $target): array
    {
        $live = $this->read($a, SetStatusType::levelOf($target), (string) $target->external_id);

        return [$live, [], []];
    }

    /**
     * Called for a Run at confirm, before the claim. A refusal moves the action proposed → failed, or proposed →
     * superseded for precondition_failed.
     *
     * `read` is the new live read when one was made (null when the propose-time read was reused).
     *
     * @return array{noop: bool, read: ?ObjectState, limits_checked: ?list<array<string, mixed>>}
     *
     * @throws WriteDenied
     */
    public function atConfirm(User $u, AdWriteAction $x): array
    {
        $account = $x->account;
        if ($account === null) {
            throw WriteDenied::make('not_found');
        }

        $expected = is_array($x->expected) ? $x->expected : [];
        $stored = $this->freshSnapshot($expected);
        $read = $stored === null ? $this->read($account, $x->target_level, $x->target_external_id) : null;
        $live = $read ?? $stored;

        $actual = $live->status !== null ? strtoupper($live->status) : null;
        if ($actual === 'ACTIVE') {
            return ['noop' => true, 'read' => $read, 'limits_checked' => null];
        }
        $want = isset($expected['status']) ? strtoupper((string) $expected['status']) : null;
        if ($actual !== self::RUNNABLE || $actual !== $want) {
            throw WriteDenied::make('precondition_failed', ['expected' => $want, 'actual' => $actual]);
        }

        return ['noop' => false, 'read' => $read, 'limits_checked' => null];
    }

    /**
     * One live read of the target. ReadUnsupported (TikTok tonight) and every other failure refuse the Run.
     *
     * @throws WriteDenied 422 budget_unreadable, 429 rate_limited
     */
    public function read(AdAccount $a, string $level, string $externalId): ObjectState
    {
        try {
            return $this->drivers->writer(AdPlatform::from($a->platform))->readObject($a, $level, $externalId);
        } catch (RateLimited $e) {
            $retry = $e->retryAfterSeconds;

            throw WriteDenied::make('rate_limited', array_filter(['retry_after' => $retry], fn ($v) => $v !== null),
                $retry !== null ? ['Retry-After' => (string) $retry] : []);
        } catch (Throwable $e) {
            if (! $e instanceof AdsApiException) {
                report($e);
            }

            throw WriteDenied::make('budget_unreadable', ['read_error' => class_basename($e)]);
        }
    }

    /** The propose-time read when it is still fresh enough to reuse, else null. */
    private function freshSnapshot(array $expected): ?ObjectState
    {
        if (! isset($expected['read_at'], $expected['live']) || ! is_array($expected['live'])) {
            return null;
        }
        try {
            $readAt = CarbonImmutable::parse((string) $expected['read_at']);
        } catch (Throwable) {
            return null;
        }
        $fresh = max(0, (int) config('crm.ads.write.preread_fresh_seconds', 60));

        return $readAt->diffInSeconds(now(), false) <= $fresh ? ObjectState::fromArray($expected['live']) : null;
    }
}
