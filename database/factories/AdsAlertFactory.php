<?php

namespace Database\Factories;

use App\Models\Ad;
use App\Models\AdsAlert;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdsAlert> */
class AdsAlertFactory extends Factory
{
    protected $model = AdsAlert::class;

    public function definition(): array
    {
        return [
            'kind' => 'alert',
            'rule_id' => 'all.spend_no_result',
            'entity_level' => 'ad',
            'ad_id' => Ad::factory(),
            'entity_id' => fn (array $a) => $a['ad_id'],
            'ad_account_id' => fn (array $a) => Ad::query()->findOrFail($a['ad_id'])->ad_account_id,
            'family' => 'SALES',
            'severity' => 'high',
            'action' => 'stop',
            'state' => AdsAlert::OPEN,
            'fingerprint' => fn (array $a) => "{$a['kind']}:{$a['rule_id']}:{$a['entity_level']}:{$a['entity_id']}",
            'dedupe_key' => fn (array $a) => $a['state'] === AdsAlert::OPEN || $a['state'] === AdsAlert::SNOOZED ? $a['fingerprint'] : null,
            'sentence_key' => 'spend_no_result',
            'params' => ['spend' => 1200, 'k' => 4, 'days' => 8, 'result' => 'purchase'],
            'evidence' => [],
            'money_at_risk_per_day' => 150,
            'first_fired_at' => now(),
            'last_evaluated_at' => now(),
        ];
    }
}
