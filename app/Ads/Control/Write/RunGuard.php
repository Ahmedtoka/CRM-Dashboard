<?php

namespace App\Ads\Control\Write;

use App\Ads\Control\Write\Types\SetStatusType;
use App\Ads\Platforms\AdPlatform;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\Data\ObjectState;
use App\Ads\Platforms\DriverFactory;
use App\Ads\Platforms\RateLimited;
use App\Ads\Platforms\ReadUnsupported;
use App\Ads\Platforms\SecretScrubber;
use App\Ads\Platforms\TokenInvalid;
use App\Ads\Reports\AdsFilter;
use App\Ads\Sync\ConnectionHealth;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdSet;
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
 * - Budget cap (budgetCheck), on the same read, at propose and at confirm.
 * - Restart lock (restartLock) at propose and at confirm; the learning note at propose. The activation caps are counted
 *   inside the claim transaction (WriteActionService::claim), under a row lock on the account.
 */
class RunGuard
{
    /** The only live statuses a Run may act on (anything else, e.g. ARCHIVED, DELETED, IN_PROCESS, is refused). */
    private const RUNNABLE = 'PAUSED';

    public function __construct(
        private readonly DriverFactory $drivers,
        private readonly WriteLimits $limits,
        private readonly SetStatusType $type,
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
        $level = SetStatusType::levelOf($target);
        $targetKey = SetStatusType::targetKey($a->id, $level, (string) $target->external_id);
        $this->restartLock($u, $targetKey);
        $live = $this->read($a, $level, (string) $target->external_id);
        $limits = $this->budgetCheck($u, $a, $target, $live);

        return [$live, $limits, $this->learningNote($u, $a, $targetKey)];
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

        $this->restartLock($u, $x->target_key);
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

        $limits = $this->budgetCheck($u, $account, $this->type->target($account, $x->target_level, $x->target_external_id), $live);

        return ['noop' => false, 'read' => $read, 'limits_checked' => $limits];
    }

    /**
     * Restart lock (B3): a Stop confirmed by an Ads-authority holder sets restart_lock_until on itself. The target is
     * locked while such a succeeded Stop's lock has not passed AND no Run on the target succeeded after that Stop
     * finished (only a holder can make one meanwhile; it lifts the lock). Other Stops in between (a buyer's second
     * Stop) change nothing. A Run by someone without Ads authority is refused. ASSUMPTION: the exact target only (a
     * campaign Stop does not lock its ads; buyers cannot Run campaigns, D4).
     *
     * @throws WriteDenied 422 restart_locked
     */
    public function restartLock(User $u, string $targetKey): void
    {
        if ($u->hasAdsAuthority()) {
            return;
        }
        $lock = AdWriteAction::where('target_key', $targetKey)->where('type', SetStatusType::TYPE)->where('to_status', 'paused')
            ->where('state', AdWriteAction::SUCCEEDED)->whereNotNull('finished_at')->where('restart_lock_until', '>', now())
            ->orderByDesc('finished_at')->orderByDesc('id')->first(); // the latest unexpired holder Stop: a Run after it is after every earlier one
        if ($lock === null) {
            return;
        }
        $lifted = AdWriteAction::where('target_key', $targetKey)->where('type', SetStatusType::TYPE)->where('to_status', 'active')
            ->whereIn('state', [AdWriteAction::SUCCEEDED, AdWriteAction::ROLLED_BACK])
            // finished_at has second precision: a Run finished in the same second counts when it was created later
            ->where(fn ($q) => $q->where('finished_at', '>', $lock->finished_at)
                ->orWhere(fn ($q) => $q->where('finished_at', $lock->finished_at)->where('id', '>', $lock->id)))
            ->exists();
        if ($lifted) {
            return;
        }
        $until = $lock->restart_lock_until;

        throw WriteDenied::make('restart_locked', [
            'until' => $until->toIso8601String(),
            'until_local' => $until->copy()->setTimezone(AdsFilter::TIMEZONE)->format('Y-m-d H:i'),
            'by' => (string) ($lock->confirmer?->name ?? ''),
        ]);
    }

    /**
     * "May re-enter learning": the CRM knows the target was paused by its own Stop (the latest succeeded action on it)
     * more than learning_note_days ago.
     *
     * @return list<array{key: string, days: int}>
     */
    public function learningNote(User $u, AdAccount $a, string $targetKey): array
    {
        $latest = $this->latestSucceeded($targetKey);
        if ($latest === null || ! $latest->isStop() || $latest->finished_at === null) {
            return [];
        }
        $threshold = $this->limits->for($u, $a)['learning_note_days'];
        if (! $latest->finished_at->lt(now()->subDays($threshold))) {
            return [];
        }

        return [['key' => 'learning_reentry', 'days' => (int) floor($latest->finished_at->diffInDays(now(), true))]];
    }

    private function latestSucceeded(string $targetKey): ?AdWriteAction
    {
        return AdWriteAction::where('target_key', $targetKey)->where('type', SetStatusType::TYPE)->where('state', AdWriteAction::SUCCEEDED)
            ->orderByDesc('finished_at')->orderByDesc('id')->first();
    }

    /**
     * The budget cap (B3, "spend/budget cap" guardrail), for everyone (Ads authority included; the owner raises his own
     * cap with ads:write-limits --user=). Relevant budgets: an ad: its ad set and its campaign (CBO); an ad set: its
     * own and its campaign's; a campaign: its own. Per-day exposure: the daily budget, else the lifetime budget spread
     * over the days until its end (ASSUMPTION: the full lifetime, not what is left, so it errs on the safe side).
     *
     * @return list<array<string, mixed>> the evaluated caps (limits_checked)
     *
     * @throws WriteDenied 422 budget_over_cap, currency_mismatch, budget_unreadable
     */
    public function budgetCheck(?User $u, AdAccount $a, Model $target, ObjectState $live): array
    {
        $verdict = $this->budgetVerdict($u, $a, $target, $live);
        if ($verdict['refusal'] !== null) {
            throw $verdict['refusal'];
        }

        return $verdict['rows'];
    }

    /**
     * The cap verdict without throwing (ads:write-preview shows it).
     *
     * @return array{rows: list<array<string, mixed>>, refusal: ?WriteDenied}
     */
    public function budgetVerdict(?User $u, AdAccount $a, Model $target, ObjectState $live): array
    {
        $limits = $this->limits->for($u, $a);
        $cap = $limits['max_daily_budget_minor'];
        $capCurrency = $limits['cap_currency'];
        // The account's own currency, never a writer default: unknown fails closed (the cap is never assumed EGP).
        $currency = strtoupper(trim((string) $a->currency));
        if ($currency === '') {
            return ['rows' => [['key' => 'cap_currency', 'limit' => $capCurrency, 'requested' => null, 'outcome' => 'unknown']],
                'refusal' => WriteDenied::make('budget_unreadable', ['reason' => 'currency_unknown'])];
        }

        $rows = [['key' => 'cap_currency', 'limit' => $capCurrency, 'requested' => $currency, 'outcome' => $currency === $capCurrency ? 'ok' : 'mismatch']];
        if ($currency !== $capCurrency) {
            return ['rows' => $rows, 'refusal' => WriteDenied::make('currency_mismatch', ['currency' => $currency, 'cap_currency' => $capCurrency])];
        }

        $refusal = null;
        $budgeted = 0;
        foreach ($this->budgetObjects($target, $live) as $o) {
            if ($o['daily'] === null && $o['lifetime'] === null) {
                continue;
            }
            $budgeted++;
            if ($o['daily'] !== null) {
                [$perDay, $basis] = [$o['daily'], 'daily'];
            } elseif ($o['endsAt'] === null) {
                $refusal ??= WriteDenied::make('budget_unreadable', ['object_level' => $o['level'], 'object_id' => $o['id'], 'reason' => 'lifetime_without_end']);

                continue;
            } else {
                $days = max(1, (int) ceil(($o['endsAt']->getTimestamp() - now()->getTimestamp()) / 86400));
                [$perDay, $basis] = [(int) ceil($o['lifetime'] / $days), 'lifetime'];
            }
            $over = $perDay > $cap;
            $rows[] = ['key' => 'max_daily_budget', 'level' => $o['level'], 'object_id' => $o['id'], 'basis' => $basis,
                'limit' => $cap, 'requested' => $perDay, 'currency' => $currency, 'outcome' => $over ? 'over' : 'ok'];
            if ($over) {
                $refusal ??= WriteDenied::make('budget_over_cap', [
                    'object_level' => $o['level'], 'object_id' => $o['id'], 'per_day_minor' => $perDay, 'cap_minor' => $cap, 'currency' => $currency,
                    'per_day' => WriteLimits::major($perDay), 'cap' => WriteLimits::major($cap),
                ]);
            }
        }
        if ($budgeted === 0) {
            $refusal ??= WriteDenied::make('budget_unreadable', ['reason' => 'no_budget']);
        }

        return ['rows' => $rows, 'refusal' => $refusal];
    }

    /**
     * The target and its parents with their budgets (nearest first). Parent ids come from the CRM rows (best effort;
     * null when the local row does not know its parent).
     *
     * @return list<array{level: string, id: ?string, daily: ?int, lifetime: ?int, endsAt: ?CarbonImmutable}>
     */
    private function budgetObjects(Model $target, ObjectState $live): array
    {
        $level = SetStatusType::levelOf($target);
        $ids = $this->parentIds($target);
        $objects = [['level' => $level, 'id' => (string) $target->external_id, 'daily' => $live->dailyBudgetMinor,
            'lifetime' => $live->lifetimeBudgetMinor, 'endsAt' => $live->endsAt]];
        foreach ($live->parents as $p) {
            $objects[] = ['level' => $p['level'], 'id' => $ids[$p['level']] ?? null, 'daily' => $p['dailyBudgetMinor'],
                'lifetime' => $p['lifetimeBudgetMinor'], 'endsAt' => $p['endsAt']];
        }

        return $objects;
    }

    /** @return array<string, string> */
    private function parentIds(Model $target): array
    {
        if ($target instanceof Ad) {
            $set = $target->adSet;
            $campaign = $target->campaign ?? $set?->campaign;

            return array_filter(['adset' => $set?->external_id, 'campaign' => $campaign?->external_id]);
        }
        if ($target instanceof AdSet) {
            return array_filter(['campaign' => $target->campaign?->external_id]);
        }

        return [];
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
        } catch (WriteDenied $e) {
            throw $e;
        } catch (TokenInvalid $e) {
            // Same handling as the executor: the connection needs a new token; the Run is refused with that code.
            if ($a->connection !== null) {
                ConnectionHealth::markNeedsReconnect($a->connection, SecretScrubber::scrub($e->getMessage()));
            }

            throw WriteDenied::make('connection_needs_reconnect');
        } catch (RateLimited $e) {
            $retry = $e->retryAfterSeconds;

            throw WriteDenied::make('rate_limited', array_filter(['retry_after' => $retry], fn ($v) => $v !== null),
                $retry !== null ? ['Retry-After' => (string) $retry] : []);
        } catch (ReadUnsupported) {
            // No live read on this platform yet (TikTok): the Run cannot be checked, so it is made in the platform's own manager.
            throw WriteDenied::make('budget_unreadable', ['read_error' => 'ReadUnsupported', 'reason' => 'read_unsupported']);
        } catch (Throwable $e) {
            if (! $e instanceof AdsApiException) {
                WriteExecutor::logUnexpected($e);
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
