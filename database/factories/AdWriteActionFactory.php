<?php

namespace Database\Factories;

use App\Models\AdAccount;
use App\Models\AdWriteAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AdWriteAction> */
class AdWriteActionFactory extends Factory
{
    protected $model = AdWriteAction::class;

    public function definition(): array
    {
        $externalId = (string) fake()->unique()->numerify('23##########');

        return [
            'type' => 'set_status',
            'state' => AdWriteAction::PROPOSED,
            'platform' => 'meta',
            'ad_account_id' => AdAccount::factory(),
            'account_name' => fake()->company(),
            'target_level' => 'ad',
            'target_external_id' => $externalId,
            'target_name' => fake()->words(3, true),
            'target_key' => fn (array $a) => self::key($a),
            'from_status' => 'ACTIVE',
            'to_status' => 'paused',
            'params' => fn (array $a) => ['status' => $a['to_status']],
            'diff' => fn (array $a) => ['status' => ['from' => $a['from_status'], 'to' => strtoupper((string) $a['to_status'])]],
            'diff_hash' => fn (array $a) => hash('sha256', json_encode($a['diff'])),
            'source' => 'ui',
            'actor_type' => 'user',
            'proposed_by_id' => User::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addMinutes(10),
        ];
    }

    public function proposed(): static
    {
        return $this->state(['state' => AdWriteAction::PROPOSED]);
    }

    public function stop(): static
    {
        return $this->state(['from_status' => 'ACTIVE', 'to_status' => 'paused']);
    }

    public function run(): static
    {
        return $this->state(['from_status' => 'PAUSED', 'to_status' => 'active']);
    }

    public function succeeded(): static
    {
        return $this->state(fn () => ['state' => AdWriteAction::SUCCEEDED, 'confirmed_at' => now(), 'executing_at' => now(), 'finished_at' => now(), 'attempts' => 1]);
    }

    public function unknown(): static
    {
        return $this->state(fn () => ['state' => AdWriteAction::UNKNOWN, 'confirmed_at' => now(), 'executing_at' => now(), 'attempts' => 1]);
    }

    /** {account_id}:{level}:{external_id}; the account id is only known once the account exists. */
    private static function key(array $a): string
    {
        $account = $a['ad_account_id'] instanceof AdAccount ? $a['ad_account_id']->id : $a['ad_account_id'];

        return $account.':'.$a['target_level'].':'.$a['target_external_id'];
    }
}
