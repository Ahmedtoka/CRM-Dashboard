<?php

namespace App\Ads\Alerts;

use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdsAlert;
use App\Models\AdsAlertEvent;
use App\Models\AdWriteAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Persistence of findings (spec 7.6, alerts spec 3.3-3.4, 5.5): one live row per dedupe key, refreshed on every
 * evaluation; a finding that stops firing is resolved with the rule's cooldown; dismiss mutes 7 days; snooze returns to
 * open; a succeeded write closes the ad's alerts as acted. No escalation re-open, no repeated re-notify (red-team 2.1).
 */
final class AlertStore
{
    public const DISMISS_REASONS = ['learning', 'seasonal', 'tiny_budget', 'wrong_numbers', 'handling_it', 'testing', 'other'];

    public const DISMISS_MUTE_DAYS = 7;

    /** @var array<string, ?int> account|day => media buyer id */
    private array $buyers = [];

    /**
     * @param  list<Rule>  $rules  the rules that ran: only their live rows may resolve
     * @param  list<Finding>  $findings
     * @param  list<int>  $liveAdIds
     * @return array{opened: list<int>, refreshed: int, resolved: int}
     */
    public function sync(?int $accountId, array $rules, array $findings, array $liveAdIds, CarbonImmutable $now): array
    {
        $byRule = [];
        foreach ($rules as $rule) {
            $byRule[$rule->id()] = $rule;
        }

        $opened = [];
        $refreshed = 0;
        $seen = [];
        foreach ($findings as $f) {
            $fp = $f->fingerprint();
            $seen[$fp] = true;
            $row = AdsAlert::query()->where('dedupe_key', $fp)->first();
            if ($row !== null) {
                $this->refresh($row, $f, $now);
                $refreshed++;

                continue;
            }
            if (AdsAlert::query()->where('fingerprint', $fp)->where('cooldown_until', '>', $now->utc())->exists()) {
                continue;
            }
            $id = $this->open($f, $now);
            if ($id !== null) {
                $opened[] = $id;
            } else {
                $refreshed++;
            }
        }

        $resolved = 0;
        AdsAlert::query()->whereIn('state', AdsAlert::LIVE_STATES)->whereIn('rule_id', array_keys($byRule))
            ->when($accountId === null, fn ($q) => $q->whereNull('ad_account_id'), fn ($q) => $q->where('ad_account_id', $accountId))
            ->get()
            ->each(function (AdsAlert $a) use ($seen, $byRule, $liveAdIds, $now, &$resolved) {
                if (isset($seen[$a->fingerprint])) {
                    return;
                }
                $reason = $a->ad_id !== null && ! in_array((int) $a->ad_id, $liveAdIds, true) ? 'not_running' : 'condition_cleared';
                $this->close($a, AdsAlert::RESOLVED, $now, ['resolved_reason' => $reason, 'cooldown_until' => $byRule[$a->rule_id]->cooldownUntil($now)->utc()]);
                $this->event($a, 'resolved', null, ['reason' => $reason]);
                $resolved++;
            });

        return ['opened' => $opened, 'refreshed' => $refreshed, 'resolved' => $resolved];
    }

    public function snooze(AdsAlert $a, User $by, CarbonImmutable $until): void
    {
        $a->forceFill(['state' => AdsAlert::SNOOZED, 'snoozed_until' => $until->utc()])->save();
        $this->event($a, 'snoozed', $by->id, ['until' => $until->toIso8601String()]);
    }

    public function wakeSnoozed(CarbonImmutable $now): int
    {
        $rows = AdsAlert::query()->where('state', AdsAlert::SNOOZED)->where('snoozed_until', '<=', $now->utc())->get();
        foreach ($rows as $a) {
            $a->forceFill(['state' => AdsAlert::OPEN, 'snoozed_until' => null])->save();
            $this->event($a, 'unsnoozed');
        }

        return $rows->count();
    }

    public function dismiss(AdsAlert $a, User $by, string $reason, ?string $note, CarbonImmutable $now): void
    {
        $this->close($a, AdsAlert::DISMISSED, $now, [
            'dismiss_reason' => $reason, 'dismiss_note' => $note !== null && $note !== '' ? mb_substr($note, 0, 500) : null,
            'cooldown_until' => $now->addDays(self::DISMISS_MUTE_DAYS)->utc(),
        ], $by);
        $this->event($a, 'dismissed', $by->id, ['reason' => $reason]);
    }

    public function markSeen(AdsAlert $a, User $by): void
    {
        if ($a->seen_at !== null) {
            return;
        }
        $a->forceFill(['seen_at' => now(), 'seen_by_id' => $by->id])->save();
        $this->event($a, 'seen', $by->id);
    }

    /** A succeeded Stop closes the ad's live alerts (except Run suggestions); a succeeded Run closes its Run suggestions. */
    public function actOnWrite(AdWriteAction $x): int
    {
        if ($x->state !== AdWriteAction::SUCCEEDED || $x->target_level !== 'ad' || $x->ad_account_id === null) {
            return 0;
        }
        $ad = Ad::query()->where('ad_account_id', $x->ad_account_id)->where('external_id', $x->target_external_id)->first();
        if ($ad === null) {
            return 0;
        }

        $q = AdsAlert::query()->where('ad_id', $ad->id)->whereIn('state', AdsAlert::LIVE_STATES);
        $x->isStop() ? $q->where('action', '!=', 'run') : $q->where('action', 'run');
        $by = $x->confirmed_by_id !== null ? User::query()->find($x->confirmed_by_id) : null;
        $now = CarbonImmutable::now();
        $n = 0;
        foreach ($q->get() as $a) {
            $this->close($a, AdsAlert::ACTED, $now, ['write_action_id' => $x->id], $by);
            $this->event($a, 'acted', $by?->id, ['write_action' => $x->public_id]);
            $n++;
        }

        return $n;
    }

    /** @param  array<string, mixed>|null  $data */
    public function event(AdsAlert $a, string $event, ?int $userId = null, ?array $data = null): void
    {
        AdsAlertEvent::query()->create(['ads_alert_id' => $a->id, 'event' => $event, 'user_id' => $userId, 'data' => $data]);
    }

    /** The media buyer holding the account on that day, read once per account and day for this store instance. */
    private function buyerId(?int $accountId, CarbonImmutable $now): ?int
    {
        if ($accountId === null) {
            return null;
        }
        $key = $accountId.'|'.$now->toDateString();
        if (! array_key_exists($key, $this->buyers)) {
            $this->buyers[$key] = AdAccount::query()->find($accountId)?->buyerOn($now->toDateString())?->id;
        }

        return $this->buyers[$key];
    }

    private function open(Finding $f, CarbonImmutable $now): ?int
    {
        try {
            $a = AdsAlert::query()->create([
                'kind' => $f->kind, 'rule_id' => $f->ruleId, 'entity_level' => $f->entityLevel, 'entity_id' => $f->entityId,
                'ad_account_id' => $f->accountId, 'ad_id' => $f->adId, 'product_id' => $f->productId,
                'buyer_id' => $this->buyerId($f->accountId, $now),
                'family' => $f->family, 'severity' => $f->severity, 'action' => $f->action, 'state' => AdsAlert::OPEN,
                'fingerprint' => $f->fingerprint(), 'dedupe_key' => $f->fingerprint(), 'sentence_key' => $f->sentenceKey,
                'params' => $f->params, 'evidence' => $f->evidence + ['computed_at' => $now->toIso8601String()],
                'money_at_risk_per_day' => round($f->moneyAtRiskPerDay, 2),
                'first_fired_at' => $now->utc(), 'last_evaluated_at' => $now->utc(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $row = AdsAlert::query()->where('dedupe_key', $f->fingerprint())->first(); // a parallel evaluation won the insert
            if ($row !== null) {
                $this->refresh($row, $f, $now);
            }

            return null;
        }
        $this->event($a, 'fired', null, ['severity' => $f->severity]);

        return $a->id;
    }

    private function refresh(AdsAlert $row, Finding $f, CarbonImmutable $now): void
    {
        $escalated = Severity::rank($f->severity) > Severity::rank($row->severity);
        $row->forceFill([
            'severity' => $f->severity, 'action' => $f->action, 'sentence_key' => $f->sentenceKey, 'params' => $f->params,
            'evidence' => $f->evidence + ['computed_at' => $now->toIso8601String()], 'family' => $f->family,
            'money_at_risk_per_day' => round($f->moneyAtRiskPerDay, 2), 'last_evaluated_at' => $now->utc(),
            'product_id' => $f->productId ?? $row->product_id,
        ])->save();
        if ($escalated) {
            $this->event($row, 'escalated', null, ['severity' => $f->severity]);
        }
    }

    /** @param  array<string, mixed>  $extra */
    private function close(AdsAlert $a, string $state, CarbonImmutable $now, array $extra = [], ?User $by = null): void
    {
        $a->forceFill(array_merge([
            'state' => $state, 'dedupe_key' => null, 'snoozed_until' => null, 'closed_at' => $now->utc(), 'closed_by_id' => $by?->id,
        ], $extra))->save();
    }
}
