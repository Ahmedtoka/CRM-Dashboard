<?php

namespace Database\Factories;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DeviceToken>
 */
class DeviceTokenFactory extends Factory
{
    public function definition(): array
    {
        $token = Str::random(22).':APA91b'.Str::random(134);

        return [
            'user_id' => User::factory(),
            'personal_access_token_id' => null,
            'platform' => 'android',
            'token' => $token,
            'token_hash' => DeviceToken::hashToken($token),
            'app_version' => '1.0.0',
            'locale' => 'ar',
            'last_registered_at' => now(),
            'failure_count' => 0,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['disabled_at' => now()]);
    }

    public function ios(): static
    {
        return $this->state(fn () => ['platform' => 'ios']);
    }
}
