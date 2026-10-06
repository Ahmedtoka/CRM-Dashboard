<?php

namespace App\Ads\Alerts;

use App\Ads\AdsSettings;
use App\Ads\Audit\AdsAudit;
use App\Ads\Launch\LaunchSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The owner's numbers behind the decisions feed (spec 7.1, 7.4), stored in ads_settings:
 *   breakeven.global / breakeven.account.{id}: margin %, shipping subsidy, return cost, target cost per purchase and per
 *   chat order (null = inherit, then "60-day median"); launch.low_stock_units (shared with S1); alerts.spike_min_amount; alerts.notify_enabled.
 * Every change is audited with before/after.
 */
final class RuleSettings
{
    public const NOTIFY_KEY = 'alerts.notify_enabled';

    /** Shared with the S1 launch checks (one low-stock number for launch and restock, A5). */
    public const LOW_STOCK_KEY = LaunchSettings::LOW_STOCK_KEY;

    public const SPIKE_KEY = 'alerts.spike_min_amount';

    public const GLOBAL_KEY = 'breakeven.global';

    public const FIELDS = ['margin_pct', 'shipping_subsidy', 'return_cost', 'target_cpp', 'target_cpo'];

    public const DEFAULT_LOW_STOCK = 10;

    public const DEFAULT_SPIKE_MIN = 1000.0;

    public function __construct(private readonly AdsSettings $settings) {}

    public static function accountKey(int $accountId): string
    {
        return 'breakeven.account.'.$accountId;
    }

    /** @return array<string, ?float> */
    public function globalInputs(): array
    {
        return $this->normalise($this->settings->get(self::GLOBAL_KEY, []));
    }

    /** @return array<string, ?float> the account's own values (null = inherit the global one) */
    public function accountInputs(int $accountId): array
    {
        return $this->normalise($this->settings->get(self::accountKey($accountId), []));
    }

    /** @return array{values: array<string, ?float>, scope: array<string, string>} */
    public function inputsFor(int $accountId): array
    {
        $global = $this->globalInputs();
        $own = $this->accountInputs($accountId);
        $values = [];
        $scope = [];
        foreach (self::FIELDS as $f) {
            [$values[$f], $scope[$f]] = match (true) {
                $own[$f] !== null => [$own[$f], 'account'],
                $global[$f] !== null => [$global[$f], 'global'],
                default => [null, 'none'],
            };
        }

        return ['values' => $values, 'scope' => $scope];
    }

    public function notifyEnabled(): bool
    {
        return (bool) $this->settings->get(self::NOTIFY_KEY, false);
    }

    public function lowStockUnits(): int
    {
        return max(0, (int) $this->settings->get(self::LOW_STOCK_KEY, self::DEFAULT_LOW_STOCK));
    }

    public function spikeMinAmount(): float
    {
        return max(0.0, (float) $this->settings->get(self::SPIKE_KEY, self::DEFAULT_SPIKE_MIN));
    }

    /** @param  array<string, mixed>  $values  only FIELDS keys are read; null or '' clears the value (inherit) */
    public function saveInputs(User $by, ?int $accountId, array $values): void
    {
        $key = $accountId === null ? self::GLOBAL_KEY : self::accountKey($accountId);
        $before = $this->normalise($this->settings->get($key, []));
        $after = $before;
        foreach (self::FIELDS as $f) {
            if (array_key_exists($f, $values)) {
                $after[$f] = $values[$f] === null || $values[$f] === '' ? null : round((float) $values[$f], 2);
            }
        }
        if ($after === $before) {
            return;
        }

        DB::transaction(function () use ($key, $before, $after, $accountId, $by) {
            $this->settings->set($key, $after);
            AdsAudit::record('settings.breakeven_changed', null, $before, $after, ['key' => $key, 'account_id' => $accountId], $by);
        });
    }

    /** @param  array{low_stock_units?: int|string, spike_min_amount?: float|string}  $values */
    public function saveGeneral(User $by, array $values): void
    {
        $before = ['low_stock_units' => $this->lowStockUnits(), 'spike_min_amount' => $this->spikeMinAmount()];
        $after = [
            'low_stock_units' => array_key_exists('low_stock_units', $values) ? max(0, (int) $values['low_stock_units']) : $before['low_stock_units'],
            'spike_min_amount' => array_key_exists('spike_min_amount', $values) ? max(0.0, round((float) $values['spike_min_amount'], 2)) : $before['spike_min_amount'],
        ];
        if ($after === $before) {
            return;
        }

        DB::transaction(function () use ($before, $after, $by) {
            $this->settings->set(self::LOW_STOCK_KEY, $after['low_stock_units']);
            $this->settings->set(self::SPIKE_KEY, $after['spike_min_amount']);
            AdsAudit::record('settings.alerts_changed', null, $before, $after, [], $by);
        });
    }

    public function setNotify(User $by, bool $on): void
    {
        $before = $this->notifyEnabled();
        if ($before === $on) {
            return;
        }

        DB::transaction(function () use ($before, $on, $by) {
            $this->settings->set(self::NOTIFY_KEY, $on);
            AdsAudit::record('settings.alerts_notify', null, ['notify_enabled' => $before], ['notify_enabled' => $on], [], $by);
        });
    }

    /** @return array<string, ?float> */
    private function normalise(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $out = [];
        foreach (self::FIELDS as $f) {
            $v = $raw[$f] ?? null;
            $out[$f] = is_numeric($v) ? (float) $v : null;
        }

        return $out;
    }
}
