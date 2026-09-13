<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BotSetting>
 */
class BotSettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'enabled' => true,
            'ai_enabled' => true,
            'ai_classifier_model' => config('crm.anthropic.classifier_model'),
            'ai_reply_model' => config('crm.anthropic.reply_model'),
            'min_confidence' => 0.60,
            'max_bot_turns' => 6,
            'handover_keywords' => ['عايز اكلم حد', 'موظف'],
            'comment_reply_delay_min' => 5,
            'comment_reply_delay_max' => 30,
        ];
    }
}
