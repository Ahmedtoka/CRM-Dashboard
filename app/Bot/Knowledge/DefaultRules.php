<?php

namespace App\Bot\Knowledge;

use App\Bot\ArabicNormalizer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The default knowledge rules seeded by data migration (spec §4.3), and the
 * helpers both seed migrations use so the owner's own rules always win:
 * defaults go BELOW the lowest active owner message rule, and a default is
 * skipped (or deactivated) when an owner rule already covers one of its
 * keywords. Query-builder only, so it is safe inside migrations.
 */
final class DefaultRules
{
    /** In order: index 0 gets the highest of the (low) default priorities. */
    public const RULES = [
        ['name' => 'ترحيب (معلومات)', 'keywords' => ['السلام عليكم', 'مساء الخير', 'صباح الخير', 'هاي', 'أهلا'], 'knowledge_key' => 'store_intro', 'sends_size_chart' => false],
        ['name' => 'مواعيد العمل', 'keywords' => ['مواعيدكم', 'بتفتحوا', 'شغالين لحد', 'مواعيد العمل'], 'knowledge_key' => 'working_hours_text', 'sends_size_chart' => false],
        ['name' => 'طرق الدفع (معلومات)', 'keywords' => ['طرق الدفع', 'الدفع', 'كاش', 'فيزا', 'انستاباي'], 'knowledge_key' => 'payment_methods', 'sends_size_chart' => false],
        ['name' => 'سياسة الاستبدال', 'keywords' => ['استبدال', 'استرجاع', 'ارجاع', 'ابدل'], 'knowledge_key' => 'exchange_policy', 'sends_size_chart' => false],
        ['name' => 'جدول المقاسات', 'keywords' => ['جدول المقاسات', 'المقاسات'], 'knowledge_key' => null, 'sends_size_chart' => true],
    ];

    /** Rows the seed created: seeded name AND its knowledge key (or the size-chart flag). */
    public static function seededRows(): Builder
    {
        return DB::table('bot_rules')->where(function (Builder $q) {
            foreach (self::RULES as $rule) {
                $q->orWhere(function (Builder $w) use ($rule) {
                    $w->where('name', $rule['name']);
                    $rule['knowledge_key'] !== null
                        ? $w->where('knowledge_key', $rule['knowledge_key'])
                        : $w->where('sends_size_chart', true);
                });
            }
        });
    }

    /** @return Collection<int, object{id: int, priority: int, keywords: list<string>}> active owner message/both rules */
    public static function ownerRules(): Collection
    {
        return DB::table('bot_rules')
            ->where('is_active', true)
            ->whereIn('scope', ['message', 'both'])
            ->whereNotIn('id', self::seededRows()->pluck('id')->all())
            ->get(['id', 'priority', 'keywords'])
            ->map(fn ($r) => (object) ['id' => (int) $r->id, 'priority' => (int) $r->priority, 'keywords' => self::decode($r->keywords)]);
    }

    /**
     * True when an owner keyword and a default keyword contain one another
     * (normalised) — the owner rule would already fire on that text.
     *
     * @param  list<string>  $keywords
     */
    public static function overlaps(array $keywords, Collection $ownerRules): bool
    {
        $normalizer = app(ArabicNormalizer::class);
        $defaults = array_filter(array_map(fn ($k) => $normalizer->normalize(trim((string) $k)), $keywords));

        foreach ($ownerRules as $owner) {
            foreach ($owner->keywords as $k) {
                $ownerKeyword = $normalizer->normalize(trim((string) $k));

                if ($ownerKeyword === '') {
                    continue;
                }

                foreach ($defaults as $d) {
                    if (str_contains($d, $ownerKeyword) || str_contains($ownerKeyword, $d)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** Priority for the default at $index, below every active owner message rule (never negative). */
    public static function priorityFor(int $index, Collection $ownerRules): int
    {
        $lowest = $ownerRules->min('priority');

        return max(0, (($lowest ?? 5) - 1) - $index);
    }

    public static function indexOf(string $name): ?int
    {
        foreach (self::RULES as $i => $rule) {
            if ($rule['name'] === $name) {
                return $i;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function decode(mixed $keywords): array
    {
        $value = is_string($keywords) ? json_decode($keywords, true) : $keywords;

        return is_array($value) ? array_values(array_map('strval', $value)) : [];
    }
}
