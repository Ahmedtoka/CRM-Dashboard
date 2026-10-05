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
 * The set_status write type (Stop / Run of a campaign, ad set or ad): the server decides exactly what the confirmation
 * dialog shows (diff) and what confirm executes (params); diff_hash binds the two (write-api 2.1, 4.1).
 */
final class SetStatusType
{
    public const TYPE = 'set_status';

    /**
     * @param  'campaign'|'adset'|'ad'  $level
     *
     * @throws WriteDenied 404 not_found when the target is not in this account in the CRM
     */
    public function target(AdAccount $a, string $level, string $externalId): Ad|AdSet|AdCampaign
    {
        $row = match ($level) {
            'campaign' => AdCampaign::where('ad_account_id', $a->id)->where('external_id', $externalId)->first(),
            'adset' => AdSet::where('external_id', $externalId)->whereHas('campaign', fn ($q) => $q->where('ad_account_id', $a->id))->first(),
            'ad' => Ad::where('ad_account_id', $a->id)->where('external_id', $externalId)->first(),
            default => null,
        };
        if ($row === null) {
            throw WriteDenied::make('not_found', ['level' => $level, 'external_id' => $externalId]);
        }

        return $row;
    }

    public static function levelOf(Model $target): string
    {
        return match (true) {
            $target instanceof AdCampaign => 'campaign',
            $target instanceof AdSet => 'adset',
            default => 'ad',
        };
    }

    public static function targetKey(int $accountId, string $level, string $externalId): string
    {
        return $accountId.':'.$level.':'.$externalId;
    }

    /** The unique business key a confirmed Run holds on its target (a Stop never takes one, 2.1 rule 3). */
    public static function runKey(string $targetKey): string
    {
        return 'run:'.$targetKey;
    }

    /**
     * @param  'active'|'paused'  $to
     * @param  array<string, mixed>  $limitsChecked
     * @param  array<int|string, mixed>  $notes
     * @return array{params: array{to: string}, diff: list<array<string, mixed>>, expected: array{status: ?string, read_at: ?string}, diff_hash: string, target_key: string, from_status: ?string, limits_checked: array<string, mixed>, notes: array<int|string, mixed>}
     */
    public function build(AdAccount $a, Model $target, string $to, ?ObjectState $live, array $limitsChecked, array $notes): array
    {
        $level = self::levelOf($target);
        $externalId = (string) $target->external_id;
        $before = $live?->status ?? ($target->status !== null ? strtoupper((string) $target->status) : null);
        $params = ['to' => $to];

        $diff = [['path' => 'status', 'before' => $before, 'after' => $to === 'active' ? 'ACTIVE' : 'PAUSED']];
        if ($to === 'active' && $live !== null) {
            // Unchanged values, shown so the human sees the spend exposure of the Run.
            $diff = array_merge($diff, $this->budgetRows($level, $live));
        }

        return [
            'params' => $params,
            'diff' => $diff,
            'expected' => ['status' => $before, 'read_at' => $live !== null ? now()->toIso8601String() : null],
            'diff_hash' => Canonical::hash([
                'type' => self::TYPE, 'account_id' => $a->id, 'level' => $level, 'external_id' => $externalId,
                'params' => $params, 'diff' => $diff,
            ]),
            'target_key' => self::targetKey($a->id, $level, $externalId),
            'from_status' => $before,
            'limits_checked' => $limitsChecked,
            'notes' => $notes,
        ];
    }

    /**
     * The undo of a finished action: a succeeded Stop is undone by a Run and a succeeded Run by a Stop; nothing else
     * has an inverse.
     *
     * @return array{to: 'active'|'paused'}|null
     */
    public function inverse(AdWriteAction $done): ?array
    {
        if ($done->state !== AdWriteAction::SUCCEEDED || $done->type !== self::TYPE) {
            return null;
        }

        return match ($done->to_status) {
            'paused' => ['to' => 'active'],
            'active' => ['to' => 'paused'],
            default => null,
        };
    }

    /** @return list<array<string, mixed>> */
    private function budgetRows(string $level, ObjectState $live): array
    {
        $money = fn (int $minor) => ['minor' => $minor, 'currency' => $live->currency];
        $rows = [];
        if ($live->dailyBudgetMinor !== null) {
            $rows[] = ['path' => 'daily_budget', 'level' => $level, 'before' => $money($live->dailyBudgetMinor), 'after' => $money($live->dailyBudgetMinor)];
        } else {
            foreach ($live->parents as $p) {
                if ($p['dailyBudgetMinor'] !== null) {
                    $rows[] = ['path' => 'parent_daily_budget', 'level' => $p['level'], 'before' => $money($p['dailyBudgetMinor']), 'after' => $money($p['dailyBudgetMinor'])];
                    break;
                }
            }
        }
        if ($live->lifetimeBudgetMinor !== null) {
            $rows[] = ['path' => 'lifetime_budget', 'level' => $level, 'before' => $money($live->lifetimeBudgetMinor), 'after' => $money($live->lifetimeBudgetMinor)];
        } else {
            foreach ($live->parents as $p) {
                if ($p['lifetimeBudgetMinor'] !== null) {
                    $rows[] = ['path' => 'lifetime_budget', 'level' => $p['level'], 'before' => $money($p['lifetimeBudgetMinor']), 'after' => $money($p['lifetimeBudgetMinor'])];
                    break;
                }
            }
        }

        return $rows;
    }
}
