<?php

namespace Database\Factories;

use App\Enums\QuickReplyScope;
use App\Models\QuickReply;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuickReply>
 */
class QuickReplyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shortcut' => '/'.fake()->unique()->word(),
            'title' => fake()->words(3, true),
            'body' => fake()->sentence(),
            'platforms' => [],
            'created_by' => User::factory(),
            'scope' => QuickReplyScope::Shared,
            'user_id' => null,
            'category_id' => null,
        ];
    }

    public function personal(User $user): static
    {
        return $this->state(fn () => ['scope' => QuickReplyScope::Personal, 'user_id' => $user->id, 'created_by' => $user->id]);
    }
}
