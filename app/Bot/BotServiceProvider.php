<?php

namespace App\Bot;

use App\Bot\Ai\AiResponder;
use App\Bot\Ai\ClaudeAiResponder;
use App\Bot\Ai\FakeAiResponder;
use App\Bot\Ai\MessageClassifier;
use App\Bot\Flow\ClaudeTurnUnderstanding;
use App\Bot\Flow\FakeTurnUnderstanding;
use App\Bot\Flow\Orders\FakeOmsClient;
use App\Bot\Flow\Orders\HttpOmsClient;
use App\Bot\Flow\Orders\OmsClient;
use App\Bot\Flow\TurnUnderstanding;
use App\Bot\Flows\ClaudeFlowAnswerInterpreter;
use App\Bot\Flows\FakeFlowAnswerInterpreter;
use App\Bot\Flows\FlowAnswerInterpreter;
use App\Bot\Flows\FlowDefinitionSource;
use App\Bot\Flows\PublishedFlowDefinitions;
use App\Bot\Flows\Returns\RemoteProductLookup;
use App\Bot\Flows\Returns\ShopifyRemoteProductLookup;
use App\Bot\Learning\ClaudeConversationReviewer;
use App\Bot\Learning\ClaudeLearningAnalyst;
use App\Bot\Learning\ConversationReviewer;
use App\Bot\Learning\FakeConversationReviewer;
use App\Bot\Learning\FakeLearningAnalyst;
use App\Bot\Learning\LearnCommand;
use App\Bot\Learning\LearningAnalyst;
use App\Models\BotSetting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class BotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Final fix wave M1: the models picked in the bot settings, config when left blank.
        $model = fn (string $column, string $config) => filled($chosen = BotSetting::current()->{$column})
            ? (string) $chosen
            : (string) config("crm.anthropic.{$config}");

        $driver = function ($app) use ($model) {
            return match (config('crm.drivers.ai', 'fake')) {
                'claude' => new ClaudeAiResponder(
                    config('crm.anthropic.key'),
                    $model('ai_classifier_model', 'classifier_model'),
                    $model('ai_reply_model', 'reply_model'),
                    (int) config('crm.anthropic.timeout', 10),
                    (int) config('crm.anthropic.classify_timeout', 3),
                    (int) config('crm.anthropic.reply_timeout', 5),
                ),
                default => $app->make(FakeAiResponder::class),
            };
        };

        $this->app->bind(AiResponder::class, $driver);
        $this->app->bind(MessageClassifier::class, $driver);

        // Conversation-turn understanding (spec §2.1): Claude only when the
        // driver is claude AND a key is set; otherwise the offline matcher.
        $this->app->bind(TurnUnderstanding::class, function ($app) use ($model) {
            return config('crm.drivers.ai', 'fake') === 'claude' && filled(config('crm.anthropic.key'))
                ? new ClaudeTurnUnderstanding(
                    config('crm.anthropic.key'),
                    $model('ai_classifier_model', 'classifier_model'),
                    (int) config('crm.anthropic.understand_timeout', 8),
                )
                : $app->make(FakeTurnUnderstanding::class);
        });

        // Exchange links (2026-09-19): a product not synced yet is fetched from the store by handle.
        $this->app->bind(RemoteProductLookup::class, ShopifyRemoteProductLookup::class);

        // Guided-flow answer interpreter (design §3): same rule as the turn understanding.
        $this->app->bind(FlowAnswerInterpreter::class, function ($app) use ($model) {
            return config('crm.drivers.ai', 'fake') === 'claude' && filled(config('crm.anthropic.key'))
                ? new ClaudeFlowAnswerInterpreter(
                    config('crm.anthropic.key'),
                    $model('ai_classifier_model', 'classifier_model'),
                    (int) config('crm.anthropic.flow_timeout', 6),
                )
                : $app->make(FakeFlowAnswerInterpreter::class);
        });

        // Nightly learning analyst (design §6): Claude only when the driver is
        // claude AND a key is set, on `ai_learning_model` falling back to the
        // classifier model; otherwise the offline counter, which proposes nothing.
        $this->app->bind(LearningAnalyst::class, function ($app) use ($model) {
            if (config('crm.drivers.ai', 'fake') !== 'claude' || blank(config('crm.anthropic.key'))) {
                return $app->make(FakeLearningAnalyst::class);
            }

            $learningModel = filled($chosen = BotSetting::current()->ai_learning_model)
                ? (string) $chosen
                : $model('ai_classifier_model', 'classifier_model');

            return new ClaudeLearningAnalyst(
                config('crm.anthropic.key'),
                $learningModel,
                (int) config('crm.anthropic.learning_timeout', 60),
            );
        });

        // Per-conversation review (learning v2 §2): same rule and model as the
        // nightly analyst; offline it writes no notes.
        $this->app->bind(ConversationReviewer::class, function ($app) use ($model) {
            if (config('crm.drivers.ai', 'fake') !== 'claude' || blank(config('crm.anthropic.key'))) {
                return $app->make(FakeConversationReviewer::class);
            }

            $learningModel = filled($chosen = BotSetting::current()->ai_learning_model)
                ? (string) $chosen
                : $model('ai_classifier_model', 'classifier_model');

            return new ClaudeConversationReviewer(
                config('crm.anthropic.key'),
                $learningModel,
                (int) config('crm.anthropic.review_timeout', 30),
            );
        });

        // Where FlowEngine reads flow definitions (flow designer §3); the sandbox swaps its own in.
        $this->app->bind(FlowDefinitionSource::class, PublishedFlowDefinitions::class);

        // Order status lookup (spec §2.1): the HTTP OMS only when the driver is live AND a base URL is set.
        $this->app->bind(OmsClient::class, fn ($app) => config('crm.drivers.oms', 'fake') === 'live' && filled(config('crm.oms.base_url'))
            ? $app->make(HttpOmsClient::class)
            : $app->make(FakeOmsClient::class));
    }

    public function boot(): void
    {
        // Registered unconditionally, NOT behind runningInConsole(): the
        // "تشغيل التعلم الآن" button calls Artisan::call('bot:learn') from a web
        // request, where a console-guarded registration is a 500.
        $this->commands([LearnCommand::class]);

        // The nightly review runs after the Cairo day it reviews has closed.
        $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule
            ->command('bot:learn')
            ->dailyAt('02:00')
            ->timezone('Africa/Cairo')
            ->withoutOverlapping());
    }
}
