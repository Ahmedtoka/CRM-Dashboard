<?php

namespace App\Ads\Control\Write\Types;

use App\Ads\Control\Write\Canonical;
use App\Ads\Control\Write\WriteDenied;
use App\Ads\Platforms\Data\ObjectState;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdCampaign;
use App\Models\AdSet;
use App\Models\AdWriteAction;
use Illuminate\Database\Eloquent\Model;

/**
 * The set_budget write type (round 3: the «scale» / «cut» decisions). A daily budget change by a percentage, or to an
 * exact amount (the undo). An ad has no budget of its own, so the write is resolved onto the nearest object in the chain
 * that holds a DAILY budget (the ad set, else the CBO campaign) and that object becomes the action's target. The server
 * fixes the exact before/after amounts in the diff; diff_hash binds the confirmation to them (write-api 2.1, 4.1).
 */
final class SetBudgetType
{
    public const TYPE = 'set_budget';

    public const MIN_PCT = -90;

    public const MAX_PCT = 200;

    /**
     * The object that holds the daily budget for $target: itself, else the first parent with one (nearest first).
     *
     * @return array{level: 'campaign'|'adset', external_id: string, minor: int}
     *
     * @throws WriteDenied 422 budget_unreadable when nothing in the chain has a daily budget
     */
    public function budgetHolder(Model $target, ObjectState $live): array
    {
        $level = SetStatusType::levelOf($target);
        if ($level !== 'ad' && $live->dailyBudgetMinor !== null && $live->dailyBudgetMinor > 0) {
            return ['level' => $level, 'external_id' => (string) $target->external_id, 'minor' => $live->dailyBudgetMinor];
        }
        $ids = $this->parentIds($target);
        foreach ($live->parents as $p) {
            if (($p['dailyBudgetMinor'] ?? null) !== null && $p['dailyBudgetMinor'] > 0 && isset($ids[$p['level']])) {
                return ['level' => $p['level'], 'external_id' => $ids[$p['level']], 'minor' => (int) $p['dailyBudgetMinor']];
            }
        }

        throw WriteDenied::make('budget_unreadable', ['reason' => 'no_budget']);
    }

    /** The CRM row of the budget holder (the action's target). */
    public function holderRow(AdAccount $a, string $level, string $externalId): AdSet|AdCampaign
    {
        $row = (new SetStatusType)->target($a, $level, $externalId);
        if ($row instanceof Ad) {
            throw WriteDenied::make('budget_unreadable', ['reason' => 'no_budget']);
        }

        return $row;
    }

    /** The new amount: a percentage of the current one (rounded to the minor unit), never below 1 minor. */
    public static function apply(int $fromMinor, int $pct): int
    {
        return max(1, (int) round($fromMinor * (100 + $pct) / 100));
    }

    /**
     * @param  array{level: string, external_id: string, minor: int}  $holder
     * @param  array<int|string, mixed>  $limitsChecked
     * @return array{params: array{pct: ?int, from_minor: int, to_minor: int, currency: string}, diff: list<array<string, mixed>>, expected: array<string, mixed>, diff_hash: string, target_key: string, from_status: ?string}
     */
    public function build(AdAccount $a, array $holder, ?int $pct, int $toMinor, ObjectState $live, array $limitsChecked): array
    {
        $currency = strtoupper((string) ($a->currency ?: $live->currency ?: 'EGP'));
        $params = ['pct' => $pct, 'from_minor' => $holder['minor'], 'to_minor' => $toMinor, 'currency' => $currency];
        $diff = [[
            'path' => 'daily_budget', 'level' => $holder['level'],
            'before' => ['minor' => $holder['minor'], 'currency' => $currency],
            'after' => ['minor' => $toMinor, 'currency' => $currency],
        ]];

        return [
            'params' => $params,
            'diff' => $diff,
            'expected' => ['daily_budget_minor' => $holder['minor'], 'read_at' => now()->toIso8601String(), 'live' => $live->toArray()],
            'diff_hash' => Canonical::hash([
                'type' => self::TYPE, 'account_id' => $a->id, 'level' => $holder['level'], 'external_id' => $holder['external_id'],
                'params' => $params, 'diff' => $diff,
            ]),
            'target_key' => SetStatusType::targetKey($a->id, $holder['level'], $holder['external_id']),
            'from_status' => $live->status,
            'limits_checked' => $limitsChecked,
        ];
    }

    /** The business key a confirmed budget change holds on its target while it executes (one at a time per object). */
    public static function budgetKey(string $targetKey): string
    {
        return 'budget:'.$targetKey;
    }

    /**
     * The undo of a succeeded budget change: the old amount back (an exact amount, no percentage).
     *
     * @return array{to_minor: int}|null
     */
    public function inverse(AdWriteAction $done): ?array
    {
        if ($done->state !== AdWriteAction::SUCCEEDED || $done->type !== self::TYPE) {
            return null;
        }
        $from = $done->params['from_minor'] ?? null;

        return is_numeric($from) && (int) $from > 0 ? ['to_minor' => (int) $from] : null;
    }

    /** @return array<string, string> level => external id of the target's parents (best effort from the CRM rows) */
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
}
