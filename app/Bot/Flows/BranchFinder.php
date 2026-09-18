<?php

namespace App\Bot\Flows;

use App\Bot\ArabicNormalizer;
use App\Bot\Flow\Concerns\CallsClaudeJson;
use App\Models\Branch;
use App\Models\BotSetting;
use Throwable;

/**
 * Resolves a customer's free-text location to a Le Voile branch area, and
 * renders the branch list for that area (a later flow step, Task 2). Tries an
 * exact alias/label match first; `guess()` falls back to one Claude call
 * choosing among the known area keys when the AI driver is enabled.
 */
final class BranchFinder
{
    use CallsClaudeJson;

    private const SYSTEM_PROMPT = "Pick the Le Voile branch area nearest to the customer's location in Egypt. Answer none if unsure.";

    public function __construct(private readonly ArabicNormalizer $normalizer) {}

    /** @return list<array{key:string, label:string, count:int}> active areas in `sort` order */
    public function areas(): array
    {
        $areas = [];

        foreach (Branch::query()->where('is_active', true)->orderBy('sort')->get(['area_key', 'area_ar', 'sort']) as $branch) {
            $key = $branch->area_key;

            if (! isset($areas[$key])) {
                $areas[$key] = ['key' => $key, 'label' => $branch->area_ar, 'count' => 0, 'sort' => $branch->sort];
            }

            $areas[$key]['count']++;
        }

        $list = array_values($areas);
        usort($list, fn (array $a, array $b) => $a['sort'] <=> $b['sort']);

        return array_map(fn (array $a) => ['key' => $a['key'], 'label' => $a['label'], 'count' => $a['count']], $list);
    }

    /** Area key whose normalized alias/label is contained in the (normalized) text; longest term wins ties. */
    public function match(string $text): ?string
    {
        $needle = $this->normalize($text);

        if ($needle === '') {
            return null;
        }

        $terms = [];

        foreach (Branch::query()->where('is_active', true)->get(['area_key', 'area_ar', 'area_en', 'aliases']) as $branch) {
            foreach (array_merge((array) $branch->aliases, [$branch->area_ar, $branch->area_en]) as $term) {
                $normalized = $this->normalize((string) $term);

                if ($normalized !== '' && ! isset($terms[$normalized])) {
                    $terms[$normalized] = $branch->area_key;
                }
            }
        }

        uksort($terms, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ($terms as $term => $key) {
            if (str_contains($needle, $term)) {
                return $key;
            }
        }

        return null;
    }

    /** `match()` first, then one Claude call among the known area keys. Fake driver, missing key, `none`, or any error: null. */
    public function guess(string $text): ?string
    {
        if (($direct = $this->match($text)) !== null) {
            return $direct;
        }

        if (config('crm.drivers.ai', 'fake') !== 'claude' || blank(config('crm.anthropic.key'))) {
            return null;
        }

        $keys = Branch::query()->where('is_active', true)->orderBy('sort')->pluck('area_key')->unique()->values()->all();

        if ($keys === []) {
            return null;
        }

        try {
            $result = $this->claudeJson(
                config('crm.anthropic.key'),
                BotSetting::current()->ai_classifier_model ?: config('crm.anthropic.classifier_model'),
                6,
                50,
                self::SYSTEM_PROMPT,
                [['role' => 'user', 'content' => $text]],
                [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => ['area' => ['type' => 'string', 'enum' => [...$keys, 'none']]],
                    'required' => ['area'],
                ],
            );
        } catch (Throwable) {
            return null;
        }

        $area = $result['json']['area'] ?? null;

        return is_string($area) && $area !== 'none' && in_array($area, $keys, true) ? $area : null;
    }

    /** One customer-facing message listing every active branch of an area. */
    public function listText(string $areaKey): string
    {
        $branches = Branch::query()->where('area_key', $areaKey)->where('is_active', true)->orderBy('sort')->get();

        if ($branches->isEmpty()) {
            return '';
        }

        $blocks = [];

        foreach ($branches as $branch) {
            $lines = ["📍 {$branch->name}", (string) $branch->address];

            if (filled($branch->hours)) {
                $lines[] = "🕘 {$branch->hours}";
            }

            $lines[] = "📞 {$branch->phone}";
            $lines[] = "🗺️ {$branch->map_url}";

            $blocks[] = implode("\n", $lines);
        }

        return "فروعنا في {$branches->first()->area_ar} 🌸\n\n".implode("\n\n", $blocks);
    }

    private function normalize(string $text): string
    {
        return trim($this->normalizer->normalize($this->normalizer->digitsToLatin($text)));
    }
}
