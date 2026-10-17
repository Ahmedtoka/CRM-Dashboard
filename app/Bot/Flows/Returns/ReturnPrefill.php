<?php

namespace App\Bot\Flows\Returns;

use App\Bot\ArabicNormalizer;
use App\Bot\Flows\EntityExtractor;

/**
 * What the message that starts a return/exchange already says (bot-hybrid.md R1/R2/R4), read
 * without a model: every order number, whether she returns, exchanges or both, and the pieces
 * she named — each with its own action, and the size/colour she wants instead.
 *
 * «عايزة ارجع البلوزة وابدل الجيبة بمقاس اكبر» → mixed; [البلوزة → return], [الجيبة → exchange, «مقاس اكبر»].
 *
 * The flow starts with this data (flowData) so it never asks again what she already wrote.
 */
final class ReturnPrefill
{
    /** Normalized verb stems (ArabicNormalizer + Franco), each opening a clause with its action. */
    private const RETURN_WORDS = ['ارجع', 'ارجاع', 'مرتجع', 'استرجع', 'استرجاع', 'رجع', 'ترجيع', 'arga3', 'arg3', 'rag3', 'return', 'refund'];

    private const EXCHANGE_WORDS = ['ابدل', 'ابدال', 'استبدل', 'استبدال', 'تبديل', 'بدل', 'abadel', 'abdel', 'tabdeel', 'exchange', 'swap'];

    /** Words that are not a piece: wanting, politeness, the order itself, linking words. */
    private const FILLER = [
        'عايزه', 'عاوزه', 'عايز', 'عاوز', 'حابه', 'حاجه', 'حاجات', 'حاجتين', 'شويه', 'محتاجه', 'ممكن', 'لو', 'سمحت', 'كمان', 'وكمان', 'بس', 'من', 'في', 'اللي', 'انا',
        'اوردر', 'الاوردر', 'اوردرات', 'الطلب', 'طلب', 'رقم', 'بتاعي', 'بتاعتي', 'دي', 'ده', 'دول', 'يا', 'فندم', 'لو سمحتي',
        '3ayza', '3ayez', 'ana', 'el', 'law', 'sam7ty', 'order', 'i', 'want', 'to', 'the', 'please', 'my',
    ];

    /** «both / all» on its own still means something (every piece); next to names it is noise. */
    private const QUANTIFIERS = ['الاتنين', 'الاثنين', 'كلهم', 'الكل', 'كله', 'كلها'];

    /**
     * @return array{order_refs: list<string>, request_kind: 'return'|'exchange'|'mixed'|null, item_hints: list<array{text:string, action:'return'|'exchange', exchange_to:?string}>}
     */
    public static function fromText(string $text): array
    {
        $norm = new ArabicNormalizer;
        $refs = EntityExtractor::orderRefs($text);
        $tokens = preg_split('/\s+/u', trim((string) preg_replace('/[^\p{L}\p{N}#\s،,+]+/u', ' ', $norm->digitsToLatin($text)))) ?: [];

        $clauses = [];
        $current = null;

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            $action = self::verb($norm, $token);

            if ($action !== null) {
                $clauses[] = ['action' => $action, 'tokens' => []];
                $current = array_key_last($clauses);

                continue;
            }

            if ($current !== null) {
                $clauses[$current]['tokens'][] = $token;
            }
        }

        $actions = array_values(array_unique(array_column($clauses, 'action')));
        $hints = [];

        foreach ($clauses as $clause) {
            [$items, $to] = self::itemsOf($norm, implode(' ', $clause['tokens']), $refs);

            if ($items !== '') {
                $hints[] = ['text' => $items, 'action' => $clause['action'], 'exchange_to' => $clause['action'] === 'exchange' ? $to : null];
            }
        }

        return [
            'order_refs' => $refs,
            'request_kind' => match (count($actions)) {
                0 => null,
                1 => $actions[0],
                default => 'mixed',
            },
            'item_hints' => $hints,
        ];
    }

    /**
     * The flow data for the $index-th order she named (the first starts now, the others wait in
     * the task queue). Pieces are only carried when she named a single order.
     *
     * @param  array{order_refs: list<string>, request_kind: ?string, item_hints: list<array>}  $prefill
     * @return array<string, mixed>
     */
    public static function flowData(array $prefill, int $index = 0): array
    {
        $kind = $prefill['request_kind'] ?? null;

        return array_filter([
            'order_ref_hint' => $prefill['order_refs'][$index] ?? null,
            'request_kind' => $kind,
            'request_kind_title' => match ($kind) {
                'return' => 'إرجاع',
                'exchange' => 'استبدال',
                'mixed' => 'إرجاع واستبدال',
                default => null,
            },
            'item_hints' => count($prefill['order_refs'] ?? []) <= 1 && ($prefill['item_hints'] ?? []) !== [] ? $prefill['item_hints'] : null,
        ], fn ($v) => $v !== null);
    }

    private static function verb(ArabicNormalizer $norm, string $token): ?string
    {
        $t = mb_strtolower($norm->normalize($token));
        // «وابدل», «وارجع»: the joining و is not part of the verb.
        $bare = str_starts_with($t, 'و') && mb_strlen($t) > 3 ? mb_substr($t, 1) : $t;

        foreach (['return' => self::RETURN_WORDS, 'exchange' => self::EXCHANGE_WORDS] as $action => $words) {
            foreach ($words as $w) {
                // The verb and its object pronoun or plural: ارجعها، ابدلهم، استبدال.
                if ($bare === $w || (mb_strlen($w) >= 3 && str_starts_with($bare, $w) && mb_strlen($bare) - mb_strlen($w) <= 3)) {
                    return $action;
                }
            }
        }

        return null;
    }

    /**
     * The clause's piece names and «بمقاس اكبر» / «بلون اسود» split apart; order numbers,
     * fillers, counts and a trailing «الاتنين» dropped.
     *
     * @param  list<string>  $refs
     * @return array{0: string, 1: ?string}
     */
    private static function itemsOf(ArabicNormalizer $norm, string $clause, array $refs): array
    {
        $to = null;

        if (preg_match('/(?:^|\s)ب\s?(مقاس|لون|موديل)\s+(\S+(?:\s+(?:اكبر|اصغر|اوسع|اضيق))?)/u', $clause, $m)) {
            $to = trim($m[1].' '.$m[2]);
            $clause = str_replace($m[0], ' ', $clause);
        }

        $kept = [];

        foreach (preg_split('/\s+/u', $clause) ?: [] as $word) {
            $n = $norm->normalize($word);
            $n = str_starts_with($n, 'و') && in_array(mb_substr($n, 1), self::FILLER, true) ? mb_substr($n, 1) : $n;

            if ($word === '' || in_array($n, self::FILLER, true) || in_array(mb_strtolower($word), self::FILLER, true)
                || in_array(ltrim($word, '#'), $refs, true) || preg_match('/^#?\d+$/', $word)
                || preg_match('/^(?:قطع|حت)/u', $n)) {
                continue;
            }

            $kept[] = $word;
        }

        // «الجيبة والبلوزة الاتنين»: the names already say which; «الاتنين» alone means both.
        $names = array_filter($kept, fn ($w) => ! in_array($norm->normalize($w), self::QUANTIFIERS, true) && trim($w, '،,+') !== '');

        if ($names !== []) {
            $kept = array_values(array_filter($kept, fn ($w) => ! in_array($norm->normalize($w), self::QUANTIFIERS, true)));
        }

        $text = trim((string) preg_replace('/^[\s،,+و]+|[\s،,+]+$/u', '', implode(' ', $kept)));

        return [$text, $to];
    }
}
