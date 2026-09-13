<?php

namespace App\Bot;

use App\Enums\Platform;
use App\Models\BotRule;
use Illuminate\Support\Collection;

/**
 * Matches inbound text against active bot rules (spec §5.7 step 3).
 */
class RuleEngine
{
    public function __construct(private readonly ArabicNormalizer $normalizer) {}

    /**
     * Finds the matching rule and increments its `hits` counter.
     *
     * @param  string  $scope  'message'|'comment'
     */
    public function match(string $text, string $scope, Platform $platform): ?BotRule
    {
        $rule = $this->peek($text, $scope, $platform);

        $rule?->increment('hits');

        return $rule;
    }

    /**
     * Finds the matching rule without incrementing `hits`. Use this for a
     * read-only check (e.g. "is there a rule for this text at all?") that
     * doesn't itself act on the rule.
     *
     * @param  string  $scope  'message'|'comment'
     */
    public function peek(string $text, string $scope, Platform $platform): ?BotRule
    {
        $normalized = $this->normalizer->normalize($text);

        $rules = BotRule::query()
            ->where('is_active', true)
            ->where(function ($q) use ($scope) {
                $q->where('scope', 'both')->orWhere('scope', $scope);
            })
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            $platforms = $rule->platforms ?? [];

            if ($platforms !== [] && ! in_array($platform->value, $platforms, true)) {
                continue;
            }

            if ($this->keywordsMatch($rule, $normalized)) {
                return $rule;
            }
        }

        return null;
    }

    private function keywordsMatch(BotRule $rule, string $normalized): bool
    {
        $keywords = $rule->keywords ?? [];

        if ($keywords === []) {
            return false;
        }

        $normalizedKeywords = Collection::make($keywords)
            ->map(fn ($k) => $this->normalizer->normalize((string) $k))
            ->filter(fn ($k) => $k !== '');

        return match ($rule->match_type) {
            'all_keywords' => $normalizedKeywords->isNotEmpty() && $normalizedKeywords->every(fn ($k) => str_contains($normalized, $k)),
            default => $normalizedKeywords->contains(fn ($k) => str_contains($normalized, $k)),
        };
    }
}
