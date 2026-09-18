<?php

namespace App\Bot\Learning;

use App\Bot\Flows\FlowDrafts;
use App\Models\BotFlow;
use App\Models\BotIntent;
use App\Models\BotKnowledgeEntry;

/**
 * The "before" side of a suggestion card, read straight from the catalog.
 * `bot:learn` snapshots it when it stores a suggestion, and the page reads
 * it again at render, so the owner always compares against what the bot
 * answers with right now — not against last night's copy.
 */
final class LearningCurrentState
{
    /** @return array{body?: string, keywords?: list<string>, text?: ?string, options?: list<string>}|null */
    public static function snapshot(string $type, ?string $target): ?array
    {
        if ($target === null || $target === '') {
            return null;
        }

        return match ($type) {
            'script_text' => self::script($target),
            'intent_keywords' => self::intent($target),
            'flow_step' => self::flowStep($target),
            default => null,
        };
    }

    private static function script(string $target): ?array
    {
        $entry = BotKnowledgeEntry::query()->where('key', SuggestionValidator::scriptKey($target))->first(['body']);

        return $entry ? ['body' => (string) $entry->body] : null;
    }

    private static function intent(string $target): ?array
    {
        $intent = BotIntent::query()->where('key', $target)->first(['keywords']);

        return $intent ? ['keywords' => array_values($intent->keywords ?? [])] : null;
    }

    private static function flowStep(string $target): ?array
    {
        $parts = SuggestionValidator::splitFlowTarget($target);

        if ($parts === null) {
            return null;
        }

        [$flowKey, $stepId] = $parts;

        $flow = BotFlow::query()->where('key', $flowKey)->first();

        if (! $flow) {
            return null;
        }

        // The draft is what a flow_step suggestion is applied to, so it is
        // also what the owner should be comparing the proposal against.
        $step = app(FlowDrafts::class)->draftFor($flow)['steps'][$stepId] ?? null;

        if (! is_array($step)) {
            return null;
        }

        return [
            'text' => is_string($step['text'] ?? null) ? $step['text'] : null,
            'options' => array_values(array_map(
                fn ($option) => is_array($option) && is_string($option['title'] ?? null) ? $option['title'] : '',
                (array) ($step['options'] ?? []),
            )),
        ];
    }
}
