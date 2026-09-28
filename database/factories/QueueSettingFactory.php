<?php

namespace Database\Factories;

use App\Models\QueueSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QueueSetting>
 */
class QueueSettingFactory extends Factory
{
    protected $model = QueueSetting::class;

    public function definition(): array
    {
        return [
            'enabled' => true,
            'points' => QueueSetting::DEFAULT_POINTS,
            'shifts' => QueueSetting::DEFAULT_SHIFTS,
            'default_roster' => [],
        ];
    }
}
