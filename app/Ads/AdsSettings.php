<?php

namespace App\Ads;

use App\Models\AdsSetting;

/** Key/value store for the Ads Hub (table ads_settings; BotSetting is single-row, so not reused). */
final class AdsSettings
{
    public const DEFAULT_THRESHOLDS = [
        'winner' => 2.0,
        'promising' => 1.3,
        'loser' => 0.8,
        'loser_min_spend' => 1000,
        'min_spend' => 500,
        'min_days' => 3,
    ];

    public function get(string $key, mixed $default = null): mixed
    {
        $row = AdsSetting::query()->where('key', $key)->first();

        return $row === null || $row->value === null ? $default : $row->value;
    }

    public function set(string $key, mixed $value): void
    {
        AdsSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public function taxRate(): float
    {
        return (float) $this->get('tax_rate', config('crm.ads.tax_rate', 0.14));
    }

    /** @return array{winner: float|int, promising: float|int, loser: float|int, loser_min_spend: float|int, min_spend: float|int, min_days: int} */
    public function winnerThresholds(): array
    {
        $saved = $this->get('winner_thresholds', []);

        return array_merge(self::DEFAULT_THRESHOLDS, is_array($saved) ? $saved : []);
    }
}
