<?php

namespace Database\Factories;

use App\Models\BotKnowledgeEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotKnowledgeEntry>
 */
class BotKnowledgeEntryFactory extends Factory
{
    protected $model = BotKnowledgeEntry::class;

    public function definition(): array
    {
        return [
            'key' => 'custom_'.fake()->unique()->lexify('????'),
            'title' => 'عنوان',
            'body' => fake()->sentence(),
            'is_active' => true,
            'is_template' => false,
            'sort' => 100,
        ];
    }
}
