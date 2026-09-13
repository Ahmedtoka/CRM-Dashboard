<?php

namespace App\Bot;

use App\Bot\Ai\AiResponder;
use App\Bot\Ai\ClaudeAiResponder;
use App\Bot\Ai\FakeAiResponder;
use Illuminate\Support\ServiceProvider;

class BotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AiResponder::class, function ($app) {
            return match (config('crm.drivers.ai', 'fake')) {
                'claude' => new ClaudeAiResponder(
                    config('crm.anthropic.key'),
                    config('crm.anthropic.classifier_model'),
                    config('crm.anthropic.reply_model'),
                    (int) config('crm.anthropic.timeout', 10),
                ),
                default => $app->make(FakeAiResponder::class),
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
