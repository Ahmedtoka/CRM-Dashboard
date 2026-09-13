<?php

namespace Database\Factories;

use App\Models\ChannelAccount;
use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Post>
 */
class PostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'channel_account_id' => ChannelAccount::factory(),
            'external_id' => (string) fake()->unique()->numberBetween(1000000, 9999999),
            'caption' => fake()->sentence(),
            'permalink' => fake()->url(),
            'is_ad' => false,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Post $post) {
            $account = $post->channel_account_id
                ? ChannelAccount::find($post->channel_account_id)
                : null;

            if ($account) {
                $post->platform = $account->platform;
            }
        });
    }
}
