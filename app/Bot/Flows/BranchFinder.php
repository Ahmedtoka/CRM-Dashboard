<?php

namespace App\Bot\Flows;

use App\Bot\ArabicNormalizer;
use App\Bot\Flow\Concerns\CallsClaudeJson;
use App\Bot\Language\KeptNames;
use App\Channels\Cards\OutboundCards;
use App\Models\BotSetting;
use App\Models\Branch;
use Illuminate\Support\Collection;
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

    /**
     * Whether the whole reply is an area's own name ("المعادي", "Nasr City"): then the area wins over a
     * branch of the same name. An alias ("الحجاز") does not count, so a branch named like it wins.
     */
    public function isAreaName(string $text): bool
    {
        $needle = mb_strtolower($this->normalize($text));

        if ($needle === '') {
            return false;
        }

        foreach (Branch::query()->where('is_active', true)->get(['area_ar', 'area_en']) as $branch) {
            foreach ([$branch->area_ar, $branch->area_en] as $term) {
                if (mb_strtolower($this->normalize((string) $term)) === $needle) {
                    return true;
                }
            }
        }

        return false;
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

    /**
     * Active branches whose name she typed (the owner's flows 4 and 5, 2026-09-19): the name as
     * written (Arabic or English, normalized), or its consonant skeleton so "المرغني" finds
     * "El Marghany" and "عباس العقاد" finds "Abbas El Akkad". Several branches with the same
     * name all come back (she then picks one); none → an empty collection.
     *
     * @return Collection<int, Branch>
     */
    public function matchBranches(string $text): Collection
    {
        $needle = mb_strtolower($this->normalize($text));

        if (mb_strlen($needle) < 3) {
            return collect();
        }

        $windows = $this->skeletonWindows($needle);
        $branches = Branch::query()->where('is_active', true)->orderBy('sort')->orderBy('id')->get();

        return $branches->filter(function (Branch $b) use ($needle, $windows) {
            $name = mb_strtolower($this->normalize(self::stripGeneric((string) $b->name)));

            if (mb_strlen($name) >= 3 && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u', $needle) === 1) {
                return true;
            }

            $skeleton = self::skeleton($name);

            return strlen($skeleton) >= 3 && in_array($skeleton, $windows, true);
        })->values();
    }

    /** The branches of an area, in the owner's order. @return Collection<int, Branch> */
    public function branchesOf(string $areaKey): Collection
    {
        return Branch::query()->where('area_key', $areaKey)->where('is_active', true)->orderBy('sort')->orderBy('id')->get();
    }

    /**
     * One card per branch (the owner's flow 5): title = name, subtitle = address + «📞 phone»
     * (+ «🕘 hours» only once the owner filled them in), buttons «📍 الخريطة» and «📞 اتصل بالفرع».
     * Each card keeps its full text too (WhatsApp, the text fallback).
     *
     * @param  iterable<Branch>  $branches
     * @return array{type:'generic', cards:list<array<string, mixed>>}
     */
    public function cards(iterable $branches): array
    {
        $cards = [];

        foreach ($branches as $b) {
            $phone = trim((string) $b->phone);
            $hours = trim((string) $b->hours);
            $tail = array_values(array_filter([$hours !== '' ? "🕘 {$hours}" : null, $phone !== '' ? "📞 {$phone}" : null]));
            $tailText = implode("\n", $tail);
            // The address gives way first: the phone must survive Messenger's 80-character subtitle.
            $room = OutboundCards::SUBTITLE_MAX - mb_strlen($tailText) - ($tailText !== '' ? 1 : 0);
            $address = trim((string) $b->address);
            $address = $room <= 1 ? '' : (mb_strlen($address) > $room ? mb_substr($address, 0, $room - 1).'…' : $address);

            $buttons = [];

            if (filled($b->map_url)) {
                $buttons[] = OutboundCards::webUrl('📍 الخريطة', (string) $b->map_url);
            }

            if ($phone !== '' && ($call = OutboundCards::call('📞 اتصل بالفرع', $phone)) !== null) {
                $buttons[] = $call;
            }

            $cards[] = [
                // A branch name and its address stay in Arabic in an English chat (§4):
                // that is what is written on the shop and what a driver needs to read.
                'title' => KeptNames::keep((string) $b->name),
                'subtitle' => KeptNames::keep(implode("\n", array_filter([$address, $tailText], fn ($p) => $p !== ''))),
                'text' => $this->branchText($b),
                'buttons' => $buttons,
            ];
        }

        return OutboundCards::generic($cards);
    }

    /** "📍 name / address / 🕘 hours / 📞 phone / 🗺️ map" (the hours line only when set). */
    public function branchText(Branch $branch): string
    {
        $lines = ["📍 {$branch->name}", (string) $branch->address];

        if (filled($branch->hours)) {
            $lines[] = "🕘 {$branch->hours}";
        }

        if (filled($branch->phone)) {
            $lines[] = "📞 {$branch->phone}";
        }

        if (filled($branch->map_url)) {
            $lines[] = "🗺️ {$branch->map_url}";
        }

        return implode("\n", array_filter($lines, fn ($l) => trim($l) !== ''));
    }

    /** Words that are not part of a branch's own name. */
    private const GENERIC_WORDS = ['فرع', 'شارع', 'ش', 'مول', 'branch', 'street', 'st', 'mall', 'road', 'rd'];

    private static function stripGeneric(string $name): string
    {
        $words = preg_split('/[\s.,\-]+/u', mb_strtolower(trim($name))) ?: [];

        return implode(' ', array_filter($words, fn ($w) => $w !== '' && ! in_array($w, self::GENERIC_WORDS, true)));
    }

    /** Skeletons of every 1–3 word run of the text (generic words and articles dropped). @return list<string> */
    private function skeletonWindows(string $text): array
    {
        $words = array_values(array_filter(preg_split('/[\s.,\-]+/u', mb_strtolower($text)) ?: [], fn ($w) => $w !== '' && ! in_array($w, self::GENERIC_WORDS, true)));
        $windows = [];

        foreach (array_keys($words) as $i) {
            for ($n = 1; $n <= 3 && $i + $n <= count($words); $n++) {
                $skeleton = self::skeleton(implode(' ', array_slice($words, $i, $n)));

                if (strlen($skeleton) >= 3) {
                    $windows[] = $skeleton;
                }
            }
        }

        return array_values(array_unique($windows));
    }

    /**
     * A rough Latin consonant skeleton shared by the Arabic and English spelling of a name:
     * articles (ال / el / al) and vowels dropped, letters mapped (ق→k, ج→g, ث→s…), doubles merged.
     */
    public static function skeleton(string $text): string
    {
        $words = preg_split('/\s+/u', mb_strtolower(trim($text))) ?: [];
        $out = '';

        foreach ($words as $word) {
            if (in_array($word, ['el', 'al', 'ال'], true)) {
                continue;
            }

            $word = preg_replace('/^(?:ال|لل|el-|al-)(?=\p{L}{2})/u', '', $word) ?? $word;
            $out .= strtr($word, self::SKELETON_MAP);
        }

        $out = preg_replace('/[^a-z]/', '', $out) ?? '';
        // A soft c (city) sounds like s; z and s are one letter here (ستارز / Stars).
        $out = preg_replace('/c(?=[eiy])/', 's', $out) ?? $out;
        $out = preg_replace('/[aeiouyw]/', '', str_replace(['q', 'j', 'c', 'z'], ['k', 'g', 'k', 's'], $out)) ?? '';

        return preg_replace('/(.)\1+/', '$1', $out) ?? '';
    }

    private const SKELETON_MAP = [
        'ا' => '', 'أ' => '', 'إ' => '', 'آ' => '', 'ى' => '', 'ي' => '', 'و' => '', 'ؤ' => '', 'ئ' => '', 'ء' => '', 'ع' => '', 'ة' => '', 'ه' => 'h',
        'ب' => 'b', 'ت' => 't', 'ث' => 's', 'ج' => 'g', 'ح' => 'h', 'خ' => 'kh', 'د' => 'd', 'ذ' => 'z', 'ر' => 'r', 'ز' => 'z',
        'س' => 's', 'ش' => 'sh', 'ص' => 's', 'ض' => 'd', 'ط' => 't', 'ظ' => 'z', 'غ' => 'gh', 'ف' => 'f', 'ق' => 'k', 'ك' => 'k',
        'ل' => 'l', 'م' => 'm', 'ن' => 'n', 'پ' => 'b', 'ڤ' => 'v', 'x' => 'ks', 'p' => 'b',
    ];

    private function normalize(string $text): string
    {
        return trim($this->normalizer->normalize($this->normalizer->digitsToLatin($text)));
    }
}
