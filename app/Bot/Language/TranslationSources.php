<?php

namespace App\Bot\Language;

use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowLabels;
use App\Bot\Flows\FlowPrompter;
use App\Bot\Flows\HumanHandover;
use App\Bot\Flows\OwnerFlowsUpgrade;
use App\Bot\Flows\ReturnFlowUpgrade;
use App\Bot\Flows\Steps\AreaStep;
use App\Bot\Flows\Steps\BranchesListStep;
use App\Bot\Flows\Steps\BranchStep;
use App\Bot\Flows\Steps\ContactStep;
use App\Bot\Flows\Steps\ItemChangesStep;
use App\Bot\Flows\Steps\OrderItemsStep;
use App\Bot\Flows\Steps\OrderStep;
use App\Bot\Flows\Steps\PhotoStep;
use App\Bot\Flows\Steps\ProductLinkStep;
use App\Bot\Flows\Steps\StatusStep;
use App\Bot\Flows\TrackingFlowUpgrade;
use App\Models\BotFlow;
use App\Models\BotKnowledgeEntry;
use ReflectionClass;

/**
 * Every Arabic text the bot can say, gathered so it can be translated once, up front
 * (`php artisan bot:translations --warm`) instead of one at a time while a customer waits.
 *
 * Three places hold them: the scripts the owner edits (`bot_knowledge_entries`), the
 * published flows (step texts and option titles), and the sentences written in code
 * (the steps' own prompts, retry texts and card labels) — collected by reflection over
 * the classes that carry them, so a new constant is picked up without touching this list.
 */
final class TranslationSources
{
    /** Classes whose Arabic string constants the bot sends. */
    private const CLASSES = [
        FlowEngine::class,
        FlowLabels::class,
        FlowPrompter::class,
        HumanHandover::class,
        OwnerFlowsUpgrade::class,
        ReturnFlowUpgrade::class,
        TrackingFlowUpgrade::class,
        AreaStep::class,
        BranchStep::class,
        BranchesListStep::class,
        ContactStep::class,
        ItemChangesStep::class,
        OrderItemsStep::class,
        OrderStep::class,
        PhotoStep::class,
        ProductLinkStep::class,
        StatusStep::class,
    ];

    /**
     * @return list<array{text:string, short:bool, context:string}> unique, in a stable order
     */
    public static function all(): array
    {
        $out = [];
        $add = function (mixed $text, bool $short, string $context) use (&$out) {
            $text = is_string($text) ? trim($text) : '';

            if ($text !== '' && TranslationMask::hasArabic($text) && ! self::isPattern($text)) {
                $out[$text] ??= ['text' => $text, 'short' => $short, 'context' => $context];
            }
        };

        foreach (BotKnowledgeEntry::query()->where('key', 'like', 'script.%')->orderBy('key')->get() as $entry) {
            $add($entry->body, false, (string) $entry->key);
        }

        foreach (BotFlow::query()->orderBy('key')->get() as $flow) {
            $add($flow->title_ar, true, 'flow.'.$flow->key);
            self::walkDefinition((array) $flow->definition, 'flow.'.$flow->key, $add);
        }

        foreach (self::CLASSES as $class) {
            $short = $class === FlowLabels::class;

            foreach ((new ReflectionClass($class))->getConstants() as $name => $value) {
                self::walkValue($value, class_basename($class).'::'.$name, $short, $add);
            }
        }

        return array_values($out);
    }

    /**
     * A matching pattern, not something the bot says: the Arabic constants that are
     * regular expressions («/بدل|استبدال|exchange/u») or lists of synonyms separated by
     * pipes. Translating them would be nonsense and would fill the settings page.
     */
    private static function isPattern(string $text): bool
    {
        $delimiter = mb_substr($text, 0, 1);

        if (in_array($delimiter, ['/', '#', '~'], true) && preg_match('/\\'.$delimiter.'[a-z]*$/u', $text) === 1) {
            return true;
        }

        return str_contains($text, '|') && ! str_contains($text, ' ');
    }

    /** Step texts and option titles of one published definition. */
    private static function walkDefinition(array $definition, string $context, callable $add): void
    {
        foreach ((array) ($definition['steps'] ?? []) as $key => $step) {
            if (! is_array($step)) {
                continue;
            }

            $add($step['text'] ?? null, false, $context.'.'.$key);

            foreach ((array) ($step['options'] ?? []) as $option) {
                if (is_array($option)) {
                    $add($option['title'] ?? null, true, $context.'.'.$key);
                }
            }
        }
    }

    private static function walkValue(mixed $value, string $context, bool $short, callable $add): void
    {
        if (is_string($value)) {
            $add($value, $short, $context);

            return;
        }

        foreach (is_array($value) ? $value : [] as $key => $item) {
            // A ['title' => …, 'payload' => …] button: only the title is sent, and it is short.
            self::walkValue($item, $context, $short || $key === 'title', $add);
        }
    }
}
