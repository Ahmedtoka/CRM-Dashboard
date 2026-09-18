<?php

namespace Database\Factories;

use App\Models\BotFlow;
use App\Models\BotFlowVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotFlowVersion>
 */
class BotFlowVersionFactory extends Factory
{
    protected $model = BotFlowVersion::class;

    public function definition(): array
    {
        return [
            'bot_flow_id' => fn () => BotFlow::create([
                'key' => 'factory_'.fake()->unique()->lexify('??????'),
                'title_ar' => 'فلو تجريبي',
                'is_active' => true,
                'definition' => ['start' => 'end', 'steps' => ['end' => ['type' => 'end']]],
            ])->id,
            'version' => 1,
            'status' => 'published',
            'definition' => ['start' => 'end', 'steps' => ['end' => ['type' => 'end']]],
            'note' => null,
            'created_by_id' => null,
            'published_by_id' => null,
            'published_at' => now(),
        ];
    }
}
